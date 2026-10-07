import { Head } from '@inertiajs/react';
import { Html5Qrcode } from 'html5-qrcode';
import { useEffect, useRef, useState } from 'react';
import { getCsrfToken } from '@/lib/csrf';
import { CheckIcon, CrossIcon, EntryIcon, ExitIcon } from '@/components/icons';
import { useTranslation } from '@/lib/i18n/context';
import AppLayout, { PageContainer } from '@/layouts/app-layout';

type ScanResult =
    | { found: true; allowed: true; name: string; checked_in: boolean }
    | { found: true; allowed: false; name: string; message: string }
    | { found: false; message: string };

type Mode = 'entry' | 'exit';

export default function Scan() {
    const { t } = useTranslation();
    const busyRef = useRef(false);
    // Codes that already got somebody in or out. Each is single-use, and the
    // camera keeps seeing the same screen for a moment after a scan; asking
    // again would only replace the success card with an "already scanned".
    const spentRef = useRef(new Set<string>());
    const [mode, setMode] = useState<Mode>('entry');
    const modeRef = useRef<Mode>(mode);
    const [result, setResult] = useState<ScanResult | null>(null);
    const [error, setError] = useState<string | null>(null);

    useEffect(() => {
        modeRef.current = mode;
    }, [mode]);

    useEffect(() => {
        const scanner = new Html5Qrcode('reader');

        const handleScan = async (code: string) => {
            try {
                const response = await fetch('/staff/scan', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                        'X-XSRF-TOKEN': getCsrfToken(),
                    },
                    credentials: 'same-origin',
                    // Read via the ref, not the `mode` state directly: this
                    // effect (and the scanner it starts) only runs once, so
                    // a closure over `mode` would keep using whatever value
                    // was current on that first render.
                    body: JSON.stringify({ code, mode: modeRef.current }),
                });

                const next: ScanResult = await response.json();

                if (next.found && next.allowed) {
                    spentRef.current.add(code);
                }

                setResult(next);
            } catch {
                setError(t('scan.unreachable'));
            }
        };

        scanner
            .start(
                { facingMode: 'environment' },
                { fps: 10, qrbox: 250 },
                (decodedText) => {
                    if (busyRef.current || spentRef.current.has(decodedText)) {
                        return;
                    }

                    busyRef.current = true;
                    void handleScan(decodedText).finally(() => {
                        setTimeout(() => {
                            busyRef.current = false;
                        }, 1500);
                    });
                },
                () => {},
            )
            .catch((err: unknown) => setError(String(err)));

        return () => {
            scanner.stop().catch(() => {});
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps -- runs the scanner setup once; `t` is intentionally not a dependency here.
    }, []);

    const modeButton = (value: Mode) =>
        `flex min-h-16 items-center justify-center gap-3 border-2 text-xl font-semibold transition active:translate-x-1 active:translate-y-1 ${
            mode === value
                ? 'border-accent bg-accent text-accent-ink shadow-hard'
                : 'border-line text-ink hover:border-ink'
        }`;

    return (
        <AppLayout>
            <Head title={t('nav.scan')} />
            <PageContainer className="flex max-w-xl flex-col gap-6">
                <h1 className="font-display text-5xl leading-none font-black uppercase">
                    {t('scan.title')}
                </h1>

                <div
                    role="group"
                    aria-label={t('scan.mode')}
                    className="grid grid-cols-2 gap-3"
                >
                    <button
                        type="button"
                        onClick={() => setMode('entry')}
                        aria-pressed={mode === 'entry'}
                        className={modeButton('entry')}
                    >
                        <EntryIcon />
                        {t('scan.entry')}
                    </button>
                    <button
                        type="button"
                        onClick={() => setMode('exit')}
                        aria-pressed={mode === 'exit'}
                        className={modeButton('exit')}
                    >
                        <ExitIcon />
                        {t('scan.exit')}
                    </button>
                </div>

                {/* html5-qrcode draws its own video and scan box in here. */}
                <div
                    id="reader"
                    className="border-accent bg-paper min-h-64 w-full overflow-hidden border-2"
                />
                <p className="text-muted text-center font-mono text-sm">
                    {t('scan.hint')}
                </p>

                {error && (
                    <p role="alert" className="text-danger font-medium">
                        {error}
                    </p>
                )}

                <div aria-live="polite">
                    {result ? (
                        <ScanResultCard
                            key={JSON.stringify(result)}
                            result={result}
                        />
                    ) : (
                        <p className="border-line text-muted flex items-center justify-center border-2 border-dashed px-5 py-7 text-base">
                            {t('scan.waiting')}
                        </p>
                    )}
                </div>
            </PageContainer>
        </AppLayout>
    );
}

function ScanResultCard({ result }: { result: ScanResult }) {
    const { t } = useTranslation();
    const allowed = result.found && result.allowed;

    return (
        <div
            className={`animate-pop-in shadow-hard-accent flex -rotate-[1.5deg] flex-col gap-1.5 border-2 border-[#0e0e10] p-5 ${
                allowed
                    ? 'bg-ok-fill text-[#0e0e10]'
                    : 'bg-danger-fill text-white'
            }`}
        >
            <p className="font-display flex items-center gap-2.5 text-4xl leading-none font-black uppercase">
                {allowed ? <CheckIcon size={30} /> : <CrossIcon size={30} />}
                {allowed
                    ? result.checked_in
                        ? t('scan.checkedIn')
                        : t('scan.checkedOut')
                    : t('scan.notAllowed')}
            </p>
            {result.found && (
                <p className="text-lg font-semibold">{result.name}</p>
            )}
            {'message' in result && (
                <p className="text-base">{result.message}</p>
            )}
        </div>
    );
}
