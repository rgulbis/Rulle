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
        if (!canvasRef.current) {
            return;
        }

        void QRCode.toCanvas(canvasRef.current, value, {
            width: size,
            margin: 1,
        });
    }, [value, size]);

    return <canvas ref={canvasRef} className="rounded-none" />;
}
