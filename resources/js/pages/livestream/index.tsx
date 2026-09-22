import { Head, Link, router, usePage } from '@inertiajs/react';
import Hls from 'hls.js';
import { useEffect, useRef, useState } from 'react';
import type { Auth } from '@/types/auth';

// Same-origin: Cloudflare Tunnel proxies this path straight to MediaMTX's
// HLS output (see docker/cloudflared-setup.sh), so there's no CORS to deal
// with and this works identically in dev and production.
const STREAM_URL = '/live-cam/index.m3u8';

export default function Livestream() {
    const { auth } = usePage<{ auth: Auth }>().props;
    const videoRef = useRef<HTMLVideoElement>(null);
    const [error, setError] = useState<string | null>(null);

    useEffect(() => {
        const video = videoRef.current;

        if (!video) {
            return;
        }

        const offlineMessage =
            "Camera feed isn't available right now — check back later.";

        setError(null);

        // Safari (and WebKit generally) plays HLS natively; every other
        // browser needs hls.js to remux it into something <video>
        // understands. The native path only reports failures through the
        // element's own `error` event, not through hls.js, so it needs its
        // own listener to show the same message instead of a silently
        // stalled player.
        if (video.canPlayType('application/vnd.apple.mpegurl')) {
            const handleNativeError = () => setError(offlineMessage);

            video.addEventListener('error', handleNativeError);
            video.src = STREAM_URL;

            return () => video.removeEventListener('error', handleNativeError);
        }

        if (!Hls.isSupported()) {
            setError("Your browser can't play this stream.");

            return;
        }

        const hls = new Hls();

        hls.on(Hls.Events.ERROR, (_event, data) => {
            if (data.fatal) {
                setError(offlineMessage);
            }
        });

        hls.loadSource(STREAM_URL);
        hls.attachMedia(video);

        return () => hls.destroy();
    }, []);

    return (
        <div className="min-h-screen bg-gray-50 text-gray-900">
            <nav className="border-b border-gray-200 bg-white">
                <div className="mx-auto flex max-w-3xl items-center justify-between px-6 py-4">
                    <Link href="/" className="font-semibold text-gray-900">
                        Rullē
                    </Link>
                    <div className="flex items-center gap-6">
                        <Link
                            href="/livestream"
                            className="text-sm font-semibold text-gray-900"
                        >
                            Livestream
                        </Link>
                        {auth.user ? (
                            <button
                                onClick={() => router.post('/logout')}
                                className="text-sm font-medium text-red-600 hover:text-red-500"
                            >
                                Log out
                            </button>
                        ) : (
                            <Link
                                href="/login"
                                className="text-sm font-medium text-gray-500 hover:text-gray-900"
                            >
                                Log in
                            </Link>
                        )}
                    </div>
                </div>
            </nav>

            <Head title="Livestream" />
            <div className="p-6">
                <div className="mx-auto flex max-w-3xl flex-col gap-4">
                    <h1 className="text-xl font-semibold text-gray-900">
                        Livestream
                    </h1>
                    <p className="text-sm text-gray-500">
                        A live look at the park. Not recorded or saved.
                    </p>

                    <div className="aspect-video w-full rounded-none border border-gray-200 bg-black">
                        {error ? (
                            <p className="flex h-full items-center justify-center px-6 text-center text-sm text-gray-300">
                                {error}
                            </p>
                        ) : (
                            <video
                                ref={videoRef}
                                controls
                                autoPlay
                                muted
                                playsInline
                                className="h-full w-full"
                            />
                        )}
                    </div>
                </div>
            </div>
        </div>
    );
}
