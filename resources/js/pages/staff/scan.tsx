import { Head } from '@inertiajs/react';
import { Html5Qrcode } from 'html5-qrcode';
import { useEffect, useRef, useState } from 'react';
import { getCsrfToken } from '@/lib/csrf';
import AppLayout from '@/layouts/app-layout';

type ScanResult =
    | { found: true; allowed: true; name: string; checked_in: boolean }
    | { found: true; allowed: false; name: string; message: string }
    | { found: false; message: string };

type Mode = 'entry' | 'exit';

export default function Scan() {
    const busyRef = useRef(false);
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

                setResult(await response.json());
            } catch {
                setError('Could not reach the server.');
            }
        };

        scanner
            .start(
                { facingMode: 'environment' },
                { fps: 10, qrbox: 250 },
                (decodedText) => {
                    if (busyRef.current) {
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
    }, []);

    return (
        <AppLayout>
            <Head title="Scan" />
            <div className="flex flex-col items-center gap-6 p-6">
                <h1 className="text-xl font-semibold text-gray-900">
                    Scan a member QR code
                </h1>

                <div className="flex w-full max-w-sm rounded-none border border-gray-200">
                    <button
                        type="button"
                        onClick={() => setMode('entry')}
                        className={`flex-1 py-2 text-sm font-semibold transition ${
                            mode === 'entry'
                                ? 'bg-yellow-400 text-black'
                                : 'bg-white text-gray-500 hover:bg-gray-50'
                        }`}
                    >
                        Entry
                    </button>
                    <button
                        type="button"
                        onClick={() => setMode('exit')}
                        className={`flex-1 border-l border-gray-200 py-2 text-sm font-semibold transition ${
                            mode === 'exit'
                                ? 'bg-yellow-400 text-black'
                                : 'bg-white text-gray-500 hover:bg-gray-50'
                        }`}
                    >
                        Exit
                    </button>
                </div>

                <div
                    id="reader"
                    className="w-full max-w-sm overflow-hidden rounded-none border border-gray-200"
                />

                {error && <p className="text-sm text-red-600">{error}</p>}

                {result && (
                    <div className="w-full max-w-sm rounded-none border border-gray-200 bg-white p-4 text-center shadow-sm">
                        {result.found && result.allowed && (
                            <>
                                <p className="font-medium text-gray-900">
                                    {result.name}
                                </p>
                                <p
                                    className={
                                        result.checked_in
                                            ? 'text-green-600'
                                            : 'text-gray-500'
                                    }
                                >
                                    {result.checked_in
                                        ? 'Checked in'
                                        : 'Checked out'}
                                </p>
                            </>
                        )}

                        {result.found && !result.allowed && (
                            <>
                                <p className="font-medium text-gray-900">
                                    {result.name}
                                </p>
                                <p className="text-red-600">{result.message}</p>
                            </>
                        )}

                        {!result.found && (
                            <p className="text-red-600">{result.message}</p>
                        )}
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
