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

    return { token: current?.token ?? null, fresh };
}
