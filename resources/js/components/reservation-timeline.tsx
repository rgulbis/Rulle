import { useRef, useState } from 'react';
import { useTranslation } from '@/lib/i18n/context';

type TimeRange = {
    starts_at: string;
    ends_at: string;
};

type Selection = {
    start: number | null;
    end: number | null;
};

type Props = {
    date: string;
    openingTime: string;
    closingTime: string;
    peakHours: number[];
    existingReservations: TimeRange[];
    minDurationMinutes: number;
    maxDurationMinutes: number;
    value: Selection;
    onChange: (value: Selection) => void;
};

const WIDTH = 800;
const HEIGHT = 150;
const BAR_TOP = 56;
const BAR_HEIGHT = 50;
const PEAK_BASELINE = BAR_TOP - 6;
const PEAK_HEIGHT = 40;

function timeToMinutes(time: string): number {
    const [hours, minutes] = time.split(':').map(Number);
    return hours * 60 + minutes;
}

function minutesToTime(minutes: number): string {
    const hours = Math.floor(minutes / 60)
        .toString()
        .padStart(2, '0');
    const mins = (minutes % 60).toString().padStart(2, '0');
    return `${hours}:${mins}`;
}

function minutesOnDate(date: string, dateTime: string): number | null {
    const d = new Date(dateTime);
    const local = `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;

    if (local !== date) {
        return null;
    }

    return d.getHours() * 60 + d.getMinutes();
}

export default function ReservationTimeline({
    date,
    openingTime,
    closingTime,
    peakHours,
    existingReservations,
    minDurationMinutes,
    maxDurationMinutes,
    value,
    onChange,
}: Props) {
    const { t } = useTranslation();
    const svgRef = useRef<SVGSVGElement>(null);
    const [hover, setHover] = useState<number | null>(null);

    const openMin = timeToMinutes(openingTime);
    const closeMin = timeToMinutes(closingTime);
    const span = closeMin - openMin;

    const now = new Date();
    const todayKey = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`;
    // Round up to the next 15-minute mark: the timeline only ever snaps to
    // :00/:15/:30/:45, so the cutoff itself has to land on one of those too,
    // or a raw "now" like 13:43 could get selected as-is and be stale (in
    // the past) by the time the form actually submits.
    const nowMinutes = now.getHours() * 60 + now.getMinutes();
    const roundedNowMinutes = Math.ceil(nowMinutes / 15) * 15;
    const pastCutoff =
        date === todayKey
            ? Math.min(closeMin, Math.max(openMin, roundedNowMinutes))
            : openMin;

    const xFor = (minutes: number) => ((minutes - openMin) / span) * WIDTH;

    const positionToMinutes = (clientX: number): number => {
        const rect = svgRef.current!.getBoundingClientRect();
        const ratio = Math.min(
            1,
            Math.max(0, (clientX - rect.left) / rect.width),
        );
        const raw = openMin + ratio * span;
        const snapped = Math.round(raw / 15) * 15;
        return Math.min(closeMin, Math.max(pastCutoff, snapped));
    };

    const handleMouseMove = (e: React.MouseEvent<SVGSVGElement>) => {
        setHover(positionToMinutes(e.clientX));
    };

    const handleClick = (e: React.MouseEvent<SVGSVGElement>) => {
        const clicked = positionToMinutes(e.clientX);

        if (value.start === null || value.end !== null) {
            onChange({ start: clicked, end: null });
            return;
        }

        if (clicked <= value.start) {
            onChange({ start: clicked, end: null });
            return;
        }

        const end = Math.min(
            closeMin,
            Math.max(value.start + minDurationMinutes, clicked),
            value.start + maxDurationMinutes,
        );
        onChange({ start: value.start, end });
    };

    const maxPeak = Math.max(1, ...peakHours);
    const firstHour = Math.ceil(openMin / 60);
    const lastHour = Math.floor(closeMin / 60);
    const hours = Array.from(
        { length: lastHour - firstHour + 1 },
        (_, i) => firstHour + i,
    );

    const peakY = (hour: number) =>
        PEAK_BASELINE - (peakHours[hour] / maxPeak) * PEAK_HEIGHT;

    const peakPoints = hours
        .map((hour) => `${xFor(hour * 60)},${peakY(hour)}`)
        .join(' ');

    const blockedRanges = existingReservations
        .map((reservation) => ({
            start: minutesOnDate(date, reservation.starts_at),
            end: minutesOnDate(date, reservation.ends_at),
        }))
        .filter(
            (range): range is { start: number; end: number } =>
                range.start !== null && range.end !== null,
        );

    return (
        <div>
            <svg
                ref={svgRef}
                viewBox={`0 0 ${WIDTH} ${HEIGHT}`}
                className="w-full cursor-pointer touch-none select-none"
                onClick={handleClick}
                onMouseMove={handleMouseMove}
                onMouseLeave={() => setHover(null)}
            >
                <polyline
                    points={peakPoints}
                    fill="none"
                    stroke="#6b7280"
                    strokeWidth={2}
                    strokeDasharray="4 4"
                    strokeLinejoin="round"
                />
                {hours.map((hour) => (
                    <circle
                        key={hour}
                        cx={xFor(hour * 60)}
                        cy={peakY(hour)}
                        r={2.5}
                        fill="#6b7280"
                    />
                ))}

                <rect
                    x={0}
                    y={BAR_TOP}
                    width={WIDTH}
                    height={BAR_HEIGHT}
                    fill="#f3f4f6"
                    stroke="#e5e7eb"
                />

                {pastCutoff > openMin && (
                    <rect
                        x={0}
                        y={BAR_TOP}
                        width={xFor(pastCutoff)}
                        height={BAR_HEIGHT}
                        fill="#d1d5db"
                        opacity={0.7}
                    />
                )}

                {blockedRanges.map((range, i) => (
                    <rect
                        key={i}
                        x={xFor(range.start)}
                        y={BAR_TOP}
                        width={xFor(range.end) - xFor(range.start)}
                        height={BAR_HEIGHT}
                        fill="#fca5a5"
                        opacity={0.6}
                    />
                ))}

                {value.start !== null && (
                    <rect
                        x={xFor(value.start)}
                        y={BAR_TOP}
                        width={
                            xFor(value.end ?? value.start) - xFor(value.start)
                        }
                        height={BAR_HEIGHT}
                        fill="#facc15"
                        opacity={0.85}
                    />
                )}

                {hover !== null && (
                    <>
                        <line
                            x1={xFor(hover)}
                            x2={xFor(hover)}
                            y1={PEAK_BASELINE - PEAK_HEIGHT}
                            y2={BAR_TOP + BAR_HEIGHT + 14}
                            stroke="#f59e0b"
                            strokeWidth={1}
                            strokeDasharray="3 3"
                        />
                        <circle
                            cx={xFor(hover)}
                            cy={BAR_TOP + BAR_HEIGHT / 2}
                            r={5}
                            fill="none"
                            stroke="#f59e0b"
                            strokeWidth={2}
                        />
                    </>
                )}

                {value.start !== null && (
                    <circle
                        cx={xFor(value.start)}
                        cy={BAR_TOP + BAR_HEIGHT / 2}
                        r={6}
                        fill="#18181b"
                    />
                )}
                {value.end !== null && (
                    <circle
                        cx={xFor(value.end)}
                        cy={BAR_TOP + BAR_HEIGHT / 2}
                        r={6}
                        fill="#18181b"
                    />
                )}

                {hours.map((hour, i) => (
                    <g key={hour}>
                        <line
                            x1={xFor(hour * 60)}
                            x2={xFor(hour * 60)}
                            y1={BAR_TOP}
                            y2={BAR_TOP + BAR_HEIGHT}
                            stroke="#e5e7eb"
                        />
                        <text
                            x={xFor(hour * 60)}
                            y={BAR_TOP + BAR_HEIGHT + 18}
                            fontSize={11}
                            fill={
                                hour * 60 < pastCutoff ? '#d1d5db' : '#9ca3af'
                            }
                            textAnchor={
                                i === 0
                                    ? 'start'
                                    : i === hours.length - 1
                                      ? 'end'
                                      : 'middle'
                            }
                            className={
                                i % 2 === 1 && i !== hours.length - 1
                                    ? 'hidden sm:inline'
                                    : undefined
                            }
                        >
                            {hour}:00
                        </text>
                    </g>
                ))}
            </svg>

            <div className="mt-2 flex items-center justify-between text-sm">
                <p className="text-gray-700">
                    {value.start !== null && value.end !== null
                        ? t('reservations.selectionSummary', {
                              start: minutesToTime(value.start),
                              end: minutesToTime(value.end),
                              minutes: value.end - value.start,
                          })
                        : value.start !== null
                          ? t('reservations.chooseEnd', {
                                start: minutesToTime(value.start),
                            })
                          : t('reservations.chooseStart')}
                </p>
                {value.start !== null && (
                    <button
                        type="button"
                        onClick={() => onChange({ start: null, end: null })}
                        className="text-xs font-medium text-red-600 hover:text-red-500"
                    >
                        {t('reservations.reset')}
                    </button>
                )}
            </div>
        </div>
    );
}

export { minutesToTime, timeToMinutes };
