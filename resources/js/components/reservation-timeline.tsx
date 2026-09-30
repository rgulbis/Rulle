import { useRef, useState } from 'react';
import type { PointerEvent as ReactPointerEvent } from 'react';
import { Label, Select } from '@/components/form-controls';
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
    // A 15-minute step is only a few screen pixels wide once the timeline is
    // squeezed onto a phone, far too thin to land on with a single tap. So on
    // top of tap-to-place, dragging a finger (or the mouse) across the bar
    // continuously extends the selection with the same live preview line
    // mouse users get for free from hovering — the drag itself does the
    // precise positioning instead of the tap location having to.
    const draggingRef = useRef(false);

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

    const handlePointerDown = (e: ReactPointerEvent<SVGSVGElement>) => {
        svgRef.current?.setPointerCapture(e.pointerId);
        draggingRef.current = true;

        const clicked = positionToMinutes(e.clientX);
        setHover(clicked);
        onChange({ start: clicked, end: null });
    };

    const handlePointerMove = (e: ReactPointerEvent<SVGSVGElement>) => {
        const pos = positionToMinutes(e.clientX);
        setHover(pos);

        if (!draggingRef.current || value.start === null) {
            return;
        }

        if (pos <= value.start) {
            onChange({ start: pos, end: null });
            return;
        }

        const end = Math.min(
            closeMin,
            Math.max(value.start + minDurationMinutes, pos),
            value.start + maxDurationMinutes,
        );
        onChange({ start: value.start, end });
    };

    const endDrag = (e: ReactPointerEvent<SVGSVGElement>) => {
        if (!draggingRef.current) {
            return;
        }

        draggingRef.current = false;
        svgRef.current?.releasePointerCapture(e.pointerId);

        // A tap with no drag leaves `end` unset — there's no separate
        // "second click" on a touchscreen, so default straight to the
        // shortest bookable block instead of stranding the user on a start
        // time they can't finish selecting.
        if (value.start !== null && value.end === null) {
            const end = Math.min(closeMin, value.start + minDurationMinutes);

            onChange(
                end > value.start
                    ? { start: value.start, end }
                    : {
                          start: Math.max(
                              pastCutoff,
                              closeMin - minDurationMinutes,
                          ),
                          end: closeMin,
                      },
            );
        }
    };

    // Dragging the bar precisely is still awkward on a touchscreen no matter
    // how big it's drawn, since the visual size was never what made 15-minute
    // increments hard to land on. A native time input turned out just as
    // fiddly in its own way (typing/spinning exact digits) — a plain list of
    // valid times to pick from is the easiest option on any device. These
    // feed the exact same value/onChange the drag interaction uses, so
    // either one works.
    const handleStartTimeChange = (raw: string) => {
        if (!raw) {
            onChange({ start: null, end: null });
            return;
        }

        const start = Number(raw);
        const currentDuration =
            value.start !== null && value.end !== null
                ? value.end - value.start
                : minDurationMinutes;
        const end = Math.min(
            closeMin,
            Math.max(start + minDurationMinutes, start + currentDuration),
            start + maxDurationMinutes,
        );

        onChange({ start, end });
    };

    const handleDurationChange = (raw: string) => {
        if (value.start === null || !raw) {
            return;
        }

        onChange({
            start: value.start,
            end: Math.min(closeMin, value.start + Number(raw)),
        });
    };

    const startTimeOptions: number[] = [];

    for (
        let minutes = pastCutoff;
        minutes <= closeMin - minDurationMinutes;
        minutes += 15
    ) {
        startTimeOptions.push(minutes);
    }

    const durationOptions: number[] = [];

    if (value.start !== null) {
        const remaining = closeMin - value.start;

        for (
            let minutes = minDurationMinutes;
            minutes <= Math.min(maxDurationMinutes, remaining);
            minutes += 15
        ) {
            durationOptions.push(minutes);
        }
    }

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
            {/* min-w-0 on each grid item + overflow-x-hidden around each
            native control: iOS Safari's own time/select chrome can ignore
            the width its CSS grid cell assigned it, otherwise overlapping
            into the next column instead of shrinking to fit — the same
            WebKit quirk the date field above had. */}
            <div className="mb-3 grid grid-cols-2 gap-3 lg:hidden">
                <div className="flex min-w-0 flex-col gap-1.5">
                    <Label htmlFor="timeline_start_time">
                        {t('reservations.startTime')}
                    </Label>
                    <div className="w-full overflow-x-hidden">
                        <Select
                            id="timeline_start_time"
                            value={value.start ?? ''}
                            onChange={(e) =>
                                handleStartTimeChange(e.target.value)
                            }
                        >
                            {value.start === null && (
                                <option value="">
                                    {t('reservations.startTimePlaceholder')}
                                </option>
                            )}
                            {startTimeOptions.map((minutes) => (
                                <option key={minutes} value={minutes}>
                                    {minutesToTime(minutes)}
                                </option>
                            ))}
                        </Select>
                    </div>
                </div>
                <div className="flex min-w-0 flex-col gap-1.5">
                    <Label htmlFor="timeline_duration">
                        {t('reservations.duration')}
                    </Label>
                    <div className="w-full overflow-x-hidden">
                        <Select
                            id="timeline_duration"
                            disabled={value.start === null}
                            value={
                                value.start !== null && value.end !== null
                                    ? value.end - value.start
                                    : ''
                            }
                            onChange={(e) =>
                                handleDurationChange(e.target.value)
                            }
                        >
                            {(value.start === null || value.end === null) && (
                                <option value="">
                                    {t('reservations.durationPlaceholder')}
                                </option>
                            )}
                            {durationOptions.map((minutes) => (
                                <option key={minutes} value={minutes}>
                                    {t('reservations.durationOption', {
                                        minutes,
                                    })}
                                </option>
                            ))}
                        </Select>
                    </div>
                </div>
            </div>

            <svg
                ref={svgRef}
                viewBox={`0 0 ${WIDTH} ${HEIGHT}`}
                className="w-full cursor-pointer touch-none select-none"
                onPointerDown={handlePointerDown}
                onPointerMove={handlePointerMove}
                onPointerUp={endDrag}
                onPointerCancel={endDrag}
                onPointerLeave={() => setHover(null)}
            >
                <polyline
                    points={peakPoints}
                    fill="none"
                    className="stroke-faint"
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
                        className="fill-faint"
                    />
                ))}

                <rect
                    x={0}
                    y={BAR_TOP}
                    width={WIDTH}
                    height={BAR_HEIGHT}
                    className="fill-paper stroke-ink"
                    strokeWidth={2}
                />

                {pastCutoff > openMin && (
                    <rect
                        x={0}
                        y={BAR_TOP}
                        width={xFor(pastCutoff)}
                        height={BAR_HEIGHT}
                        className="fill-line"
                        opacity={0.8}
                    />
                )}

                {blockedRanges.map((range, i) => (
                    <rect
                        key={i}
                        x={xFor(range.start)}
                        y={BAR_TOP}
                        width={xFor(range.end) - xFor(range.start)}
                        height={BAR_HEIGHT}
                        className="fill-live"
                        opacity={0.45}
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
                        className="fill-accent"
                    />
                )}

                {hover !== null && (
                    <>
                        <line
                            x1={xFor(hover)}
                            x2={xFor(hover)}
                            y1={PEAK_BASELINE - PEAK_HEIGHT}
                            y2={BAR_TOP + BAR_HEIGHT + 14}
                            className="stroke-ink"
                            strokeWidth={1}
                            strokeDasharray="3 3"
                        />
                        <circle
                            cx={xFor(hover)}
                            cy={BAR_TOP + BAR_HEIGHT / 2}
                            r={5}
                            fill="none"
                            className="stroke-ink"
                            strokeWidth={2}
                        />
                    </>
                )}

                {value.start !== null && (
                    <circle
                        cx={xFor(value.start)}
                        cy={BAR_TOP + BAR_HEIGHT / 2}
                        r={6}
                        className="fill-ink"
                    />
                )}
                {value.end !== null && (
                    <circle
                        cx={xFor(value.end)}
                        cy={BAR_TOP + BAR_HEIGHT / 2}
                        r={6}
                        className="fill-ink"
                    />
                )}

                {hours.map((hour, i) => (
                    <g key={hour}>
                        <line
                            x1={xFor(hour * 60)}
                            x2={xFor(hour * 60)}
                            y1={BAR_TOP}
                            y2={BAR_TOP + BAR_HEIGHT}
                            className="stroke-line"
                        />
                        <text
                            x={xFor(hour * 60)}
                            y={BAR_TOP + BAR_HEIGHT + 18}
                            fontSize={11}
                            className={`${
                                hour * 60 < pastCutoff
                                    ? 'fill-line'
                                    : 'fill-muted'
                            } ${
                                i % 2 === 1 && i !== hours.length - 1
                                    ? 'hidden sm:inline'
                                    : ''
                            }`}
                            textAnchor={
                                i === 0
                                    ? 'start'
                                    : i === hours.length - 1
                                      ? 'end'
                                      : 'middle'
                            }
                        >
                            {hour}:00
                        </text>
                    </g>
                ))}
            </svg>

            <div className="mt-2 flex items-center justify-between text-sm">
                <p className="text-ink font-medium">
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
                        className="text-danger min-h-11 px-2 font-semibold underline underline-offset-4"
                    >
                        {t('reservations.reset')}
                    </button>
                )}
            </div>
        </div>
    );
}

export { minutesToTime, timeToMinutes };
