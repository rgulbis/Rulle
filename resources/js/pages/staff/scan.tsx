import { Head } from '@inertiajs/react';
import { Html5Qrcode } from 'html5-qrcode';
import { useEffect, useRef, useState } from 'react';
import { getCsrfToken } from '@/lib/csrf';
import AppLayout from '@/layouts/app-layout';

type ScanResult =
    | { found: true; allowed: true; name: string; checked_in: boolean }
    | { found: true; allowed: false; name: string; message: string }
    | { found: false; message: string };

export default function Scan() {
    const busyRef = useRef(false);
    const [result, setResult] = useState<ScanResult | null>(null);
    const [error, setError] = useState<string | null>(null);

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
                    body: JSON.stringify({ code }),
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
                <h1 className="text-lg font-medium">Scan a member QR code</h1>

                <div
                    id="reader"
                    className="w-full max-w-sm overflow-hidden rounded-md"
                />

                {error && (
                    <p className="text-sm text-[#f53003] dark:text-[#FF4433]">
                        {error}
                    </p>
                )}

                {result && (
                    <div className="w-full max-w-sm rounded-md border border-[#e3e3e0] p-4 text-center dark:border-[#3E3E3A]">
                        {result.found && result.allowed && (
                            <>
                                <p className="font-medium">{result.name}</p>
                                <p
                                    className={
                                        result.checked_in
                                            ? 'text-green-600 dark:text-green-500'
                                            : 'text-[#706f6c] dark:text-[#A1A09A]'
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
                                <p className="font-medium">{result.name}</p>
                                <p className="text-[#f53003] dark:text-[#FF4433]">
                                    {result.message}
                                </p>
                            </>
                        )}

                        {!result.found && (
                            <p className="text-[#f53003] dark:text-[#FF4433]">
                                {result.message}
                            </p>
                        )}
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
