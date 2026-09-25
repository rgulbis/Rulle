import { Head } from '@inertiajs/react';
import Hls from 'hls.js';
import { useEffect, useRef, useState } from 'react';
import AppLayout from '@/layouts/app-layout';
import { useTranslation } from '@/lib/i18n/context';

// Same-origin: Cloudflare Tunnel proxies this path straight to MediaMTX's
// HLS output (see docker/cloudflared-setup.sh), so there's no CORS to deal
// with and this works identically in dev and production.
const STREAM_URL = '/live-cam/index.m3u8';

type TimeRange = {
    starts_at: string;
    ends_at: string;
};

type Props = {
    checkedInCount: number;
    todaysReservations: TimeRange[];
};

export default function Livestream({
    checkedInCount: initialCount,
    todaysReservations,
}: Props) {
    const { t, tCount, intlLocale } = useTranslation();
    const videoRef = useRef<HTMLVideoElement>(null);
    // A key into the dictionary, not the formatted message itself — so
    // switching language afterwards updates text that's already on screen,
    // without needing to re-run the effect below (which would needlessly
    // restart the stream) just because the locale changed.
    const [error, setError] = useState<'offline' | 'unsupported' | null>(null);
    const [checkedInCount, setCheckedInCount] = useState(initialCount);

    const formatTime = (dateTime: string) =>
        new Date(dateTime).toLocaleTimeString(intlLocale, {
            hour: '2-digit',
            minute: '2-digit',
        });

    useEffect(() => {
        // Public channel — no auth needed, matching this being a public
        // page. A headcount isn't sensitive the way who's inside is.
        const channel = window.Echo.channel('occupancy');

        channel.listen('.occupancy.updated', (e: { count: number }) => {
            setCheckedInCount(e.count);
        });

        return () => {
            window.Echo.leave('occupancy');
        };
    }, []);

    useEffect(() => {
        const video = videoRef.current;

        if (!video) {
            return;
        }

        setError(null);

        // Safari (and WebKit generally) plays HLS natively; every other
        // browser needs hls.js to remux it into something <video>
        // understands. The native path only reports failures through the
        // element's own `error` event, not through hls.js, so it needs its
        // own listener to show the same message instead of a silently
        // stalled player.
        if (video.canPlayType('application/vnd.apple.mpegurl')) {
            const handleNativeError = () => setError('offline');

            video.addEventListener('error', handleNativeError);
            video.src = STREAM_URL;

            return () => video.removeEventListener('error', handleNativeError);
        }

        if (!Hls.isSupported()) {
            setError('unsupported');

            return;
        }

        const hls = new Hls();

        hls.on(Hls.Events.ERROR, (_event, data) => {
            if (data.fatal) {
                setError('offline');
            }
        });

        hls.loadSource(STREAM_URL);
        hls.attachMedia(video);

        return () => hls.destroy();
    }, []);

    return (
        <AppLayout>
            <Head title={t('livestream.title')} />
            <div className="p-6">
                <div className="mx-auto flex max-w-3xl flex-col gap-4">
                    <h1 className="text-xl font-semibold text-gray-900">
                        {t('livestream.title')}
                    </h1>
                    <p className="text-sm text-gray-500">
                        {t('livestream.subtitle')}
                    </p>

                    <div className="flex flex-wrap items-center gap-4 text-sm">
                        <span className="inline-flex items-center gap-1.5 rounded-none border border-gray-200 bg-white px-3 py-1.5 font-medium text-gray-900">
                            <span className="h-2 w-2 rounded-full bg-green-500" />
                            {tCount(
                                'livestream.checkedInCount',
                                checkedInCount,
                            )}
                        </span>
                        <span className="text-gray-500">
                            {todaysReservations.length === 0
                                ? t('livestream.noReservationsToday')
                                : t('livestream.reservedToday', {
                                      ranges: todaysReservations
                                          .map(
                                              (r) =>
                                                  `${formatTime(r.starts_at)}–${formatTime(r.ends_at)}`,
                                          )
                                          .join(', '),
                                  })}
                        </span>
                    </div>

                    <div className="aspect-video w-full rounded-none border border-gray-200 bg-black">
                        {error ? (
                            <p className="flex h-full items-center justify-center px-6 text-center text-sm text-gray-300">
                                {t(
                                    error === 'offline'
                                        ? 'livestream.offline'
                                        : 'livestream.unsupportedBrowser',
                                )}
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
        </AppLayout>
    );
}
