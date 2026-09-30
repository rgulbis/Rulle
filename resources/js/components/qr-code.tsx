import QRCode from 'qrcode';
import { useEffect, useRef } from 'react';

export default function QrCode({
    value,
    size = 220,
}: {
    value: string;
    size?: number;
}) {
    const canvasRef = useRef<HTMLCanvasElement>(null);

    useEffect(() => {
        // No code yet (e.g. a seeded account) — draw nothing rather than
        // letting the library throw on empty input.
        if (!canvasRef.current || !value) {
            return;
        }

        void QRCode.toCanvas(canvasRef.current, value, {
            width: size,
            margin: 1,
        });
    }, [value, size]);

    // Drawn at `size` but allowed to shrink with its container on narrow
    // phones, rather than pushing the page wider than the screen.
    return (
        <canvas ref={canvasRef} className="h-auto max-w-full rounded-none" />
    );
}
