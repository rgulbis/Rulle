import { useCallback, useEffect, useRef, useState } from 'react';

export type QrTokenData = {
    token: string;
    // Seconds the token lives for, counted from when the server issued it.
    ttl: number;
};

// Ask for a new one after this share of the lifetime has passed, so the one
// on screen always has most of its life left when staff scan it.
const REFRESH_AT = 0.6;
const RETRY_AFTER_MS = 5000;

/**
 * Keeps an entry QR token fresh: each one is short-lived and single-use, so
 * the screen asks for the next before the current one runs out. `fresh` goes
 * false when the current token has outlived its lifetime without a
 * replacement arriving (offline, server down), so the page can stop showing a
 * code that won't scan.
 *
 * Time is measured from when a token arrived here, never against the
 * server's clock, so a phone with a wrong clock behaves the same.
 */
export function useQrToken(initial: QrTokenData | null) {
    const [current, setCurrent] = useState(initial);
    const [fresh, setFresh] = useState(initial !== null);
    const refreshTimer = useRef<number>(undefined);
    const expiryTimer = useRef<number>(undefined);
    // While set, a code that arrives is held back until this moment (ms
    // since epoch): see rotate().
    const holdUntil = useRef(0);
    const refreshRef = useRef<() => Promise<void>>(undefined);

    const accept = useCallback((next: QrTokenData) => {
        setCurrent(next);
        setFresh(true);
        window.clearTimeout(expiryTimer.current);
        expiryTimer.current = window.setTimeout(
            () => setFresh(false),
            next.ttl * 1000,
        );
    }, []);

    useEffect(() => {
        let cancelled = false;

        const refresh = async () => {
            window.clearTimeout(refreshTimer.current);

            // A hidden tab isn't being shown to anyone; it refreshes the
            // moment it's visible again instead of polling in the background.
            if (document.hidden) {
                return;
            }

            try {
                const response = await fetch('/dashboard/qr-token', {
                    headers: { Accept: 'application/json' },
                    credentials: 'same-origin',
                    cache: 'no-store',
                });

                if (!response.ok) {
                    throw new Error(String(response.status));
                }

                const next = (await response.json()) as QrTokenData;

                if (cancelled) {
                    return;
                }

                // Right after a scan the code stays hidden for a moment,
                // so a camera that is still pointed at the screen can't
                // read the new one straight away. A code that arrives
                // early is simply asked for again once the hold is over.
                const held = holdUntil.current - Date.now();

                if (held > 0) {
                    refreshTimer.current = window.setTimeout(
                        () => void refresh(),
                        held,
                    );

                    return;
                }

                accept(next);
                refreshTimer.current = window.setTimeout(
                    () => void refresh(),
                    next.ttl * REFRESH_AT * 1000,
                );
            } catch {
                if (!cancelled) {
                    refreshTimer.current = window.setTimeout(
                        () => void refresh(),
                        RETRY_AFTER_MS,
                    );
                }
            }
        };

        refreshRef.current = refresh;

        const onVisible = () => {
            if (!document.hidden) {
                void refresh();
            }
        };

        document.addEventListener('visibilitychange', onVisible);

        if (initial) {
            accept(initial);
            refreshTimer.current = window.setTimeout(
                () => void refresh(),
                initial.ttl * REFRESH_AT * 1000,
            );
        } else {
            void refresh();
        }

        return () => {
            cancelled = true;
            document.removeEventListener('visibilitychange', onVisible);
            window.clearTimeout(refreshTimer.current);
            window.clearTimeout(expiryTimer.current);
        };
        // `initial` only seeds the first token; later ones come from polling.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [accept]);

    /**
     * Retires the code on screen at once and shows a new one after
     * `delayMs`. For right after a successful scan: that code is spent
     * anyway, and a short pause before the next appears makes a second,
     * accidental scan of the same screen unlikely.
     */
    const rotate = useCallback((delayMs: number) => {
        window.clearTimeout(refreshTimer.current);
        window.clearTimeout(expiryTimer.current);
        holdUntil.current = Date.now() + delayMs;
        setFresh(false);
        refreshTimer.current = window.setTimeout(
            () => void refreshRef.current?.(),
            delayMs,
        );
    }, []);

    return { token: current?.token ?? null, fresh, rotate };
}
