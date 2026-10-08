import type Hls from 'hls.js';
import { useEffect, useRef, useState } from 'react';
import { useTranslation } from '@/lib/i18n/context';

// Same-origin: Cloudflare Tunnel proxies this path straight to MediaMTX's
// HLS output (see docker/cloudflared-setup.sh), so there's no CORS to deal
// with and this works identically in dev and production.
const STREAM_URL = '/live-cam/index.m3u8';

/**
 * The headcount the busy meter treats as "full". There's no capacity
 * setting in the app yet, so this is a display scale, not a limit —
 * change it here if the park's real comfortable maximum differs.
 */
export const BUSY_SCALE = 40;

export function LiveVideo({ className = '' }: { className?: string }) {
    const { t } = useTranslation();
    const videoRef = useRef<HTMLVideoElement>(null);
    // A key into the dictionary, not the formatted message itself — so
    // switching language afterwards updates text that's already on screen,
    // without needing to re-run the effect below (which would needlessly
    // restart the stream) just because the locale changed.
    const [error, setError] = useState<'offline' | 'unsupported' | null>(null);

    useEffect(() => {
        const video = videoRef.current;

        if (!video) {
            return;
        }

        setError(null);

        let hls: Hls | null = null;
        let cancelled = false;

        // Browsers pause (or throttle) background and occluded video to save
        // power, and there are no controls here for a viewer to press play
        // again — so without this the stream stays frozen until a refresh.
        // Whatever time passed while paused is also gone from the live
        // playlist, so jump back to the live edge instead of resuming from
        // a position the server no longer has.
        const resume = () => {
            if (document.hidden) {
                return;
            }

            const liveEdge =
                hls?.liveSyncPosition ??
                (video.seekable.length > 0
                    ? video.seekable.end(video.seekable.length - 1)
                    : null);

            if (liveEdge !== null && liveEdge - video.currentTime > 5) {
                video.currentTime = liveEdge;
            }

            video.play().catch(() => {});
        };

        const handleVisibility = () => resume();

        // The player has no controls, so any pause while the page is visible
        // wasn't the viewer's doing.
        const handlePause = () => {
            if (!document.hidden && !video.ended) {
                resume();
            }
        };

        document.addEventListener('visibilitychange', handleVisibility);
        window.addEventListener('focus', resume);
        window.addEventListener('pageshow', resume);
        video.addEventListener('pause', handlePause);

        const removeListeners = () => {
            document.removeEventListener('visibilitychange', handleVisibility);
            window.removeEventListener('focus', resume);
            window.removeEventListener('pageshow', resume);
            video.removeEventListener('pause', handlePause);
        };

        // Native HLS is only a fallback. Chrome 142+ also answers "maybe" to
        // canPlayType for HLS, but its built-in player stalls on this
        // stream after a few seconds, so hls.js (MediaSource) goes first
        // wherever it can run. That leaves native playback for browsers
        // with no MediaSource at all (older iPhones). Native playback only
        // reports failures through the element's own `error` event, so it
        // needs its own listener to show the same message instead of a
        // silently stalled player.
        const playNatively = () => {
            if (!video.canPlayType('application/vnd.apple.mpegurl')) {
                setError('unsupported');

                return;
            }

            video.addEventListener('error', () => setError('offline'));
            video.src = STREAM_URL;
        };

        // hls.js is ~500 kB, so it's only fetched here, not bundled into
        // every page that shows the player (the home page included).
        void import('hls.js').then(({ default: HlsPlayer }) => {
            if (cancelled) {
                return;
            }

            if (!HlsPlayer.isSupported()) {
                playNatively();

                return;
            }

            hls = new HlsPlayer();

            // A tab left in the background for a while commonly comes back
            // with a fatal network/media error (expired segments, a decoder
            // the browser tore down). Retry a few times before declaring the
            // stream offline; any successfully loaded fragment resets this.
            let recoveries = 0;

            hls.on(HlsPlayer.Events.FRAG_LOADED, () => {
                recoveries = 0;
            });

            hls.on(HlsPlayer.Events.ERROR, (_event, data) => {
                if (!data.fatal) {
                    return;
                }

                if (recoveries < 3) {
                    recoveries++;

                    if (data.type === HlsPlayer.ErrorTypes.MEDIA_ERROR) {
                        hls?.recoverMediaError();

                        return;
                    }

                    if (data.type === HlsPlayer.ErrorTypes.NETWORK_ERROR) {
                        hls?.startLoad();

                        return;
                    }
                }

                setError('offline');
            });

            hls.loadSource(STREAM_URL);
            hls.attachMedia(video);
        });

        return () => {
            cancelled = true;
            removeListeners();
            hls?.destroy();
        };
    }, []);

    return (
        <div className={`relative bg-[#2a2a2e] ${className}`}>
            {error ? (
                <p className="flex h-full items-center justify-center px-6 text-center text-base text-[#b9b8b2]">
                    {t(
                        error === 'offline'
                            ? 'livestream.offline'
                            : 'livestream.unsupportedBrowser',
                    )}
                </p>
            ) : (
                <video
                    ref={videoRef}
                    autoPlay
                    muted
                    playsInline
                    disablePictureInPicture
                    disableRemotePlayback
                    onContextMenu={(e) => e.preventDefault()}
                    className="pointer-events-none h-full w-full object-cover"
                />
            )}
        </div>
    );
}

/** Live headcount, kept current over the public `occupancy` channel. */
export function useOccupancy(initialCount: number): number {
    const [count, setCount] = useState(initialCount);

    useEffect(() => {
        setCount(initialCount);
    }, [initialCount]);

    useEffect(() => {
        // Public channel — no auth needed, matching this being a public
        // page. A headcount isn't sensitive the way who's inside is.
        const channel = window.Echo.channel('occupancy');

        channel.listen('.occupancy.updated', (e: { count: number }) => {
            setCount(e.count);
        });

        return () => {
            window.Echo.leave('occupancy');
        };
    }, []);

    return count;
}

export function busyLabelKey(count: number) {
    if (count < BUSY_SCALE / 4) return 'occupancy.quiet' as const;
    if (count < (BUSY_SCALE * 5) / 8) return 'occupancy.gettingBusy' as const;

    return 'occupancy.busy' as const;
}

export function OccupancyMeter({
    count,
    filled = 'bg-ink',
    empty = 'bg-line',
}: {
    count: number;
    filled?: string;
    empty?: string;
}) {
    const { t } = useTranslation();
    const level = Math.min(10, Math.round((count / BUSY_SCALE) * 10));

    return (
        <div
            role="img"
            aria-label={t('occupancy.meterLabel', { level })}
            className="grid grid-cols-10 gap-1"
        >
            {Array.from({ length: 10 }, (_, i) => (
                <span key={i} className={`h-3 ${i < level ? filled : empty}`} />
            ))}
        </div>
    );
}

export function LiveBadge({ className = '' }: { className?: string }) {
    const { t } = useTranslation();

    return (
        <span
            className={`bg-live inline-flex items-center gap-2 px-2.5 py-1 font-mono text-xs font-semibold tracking-[0.12em] text-white ${className}`}
        >
            <span
                aria-hidden="true"
                className="animate-live-pulse size-1.5 rounded-full bg-white"
            />
            {t('livestream.live')}
        </span>
    );
}

/** "14:00–16:00, 18:00–19:00" in the visitor's locale. */
export function useFormatRanges() {
    const { intlLocale } = useTranslation();

    const formatTime = (dateTime: string) =>
        new Date(dateTime).toLocaleTimeString(intlLocale, {
            hour: '2-digit',
            minute: '2-digit',
        });

    return (ranges: { starts_at: string; ends_at: string }[]) =>
        ranges
            .map((r) => `${formatTime(r.starts_at)}–${formatTime(r.ends_at)}`)
            .join(', ');
}
