import { useRef, useState } from 'react';
import type {
    MouseEvent as ReactMouseEvent,
    PointerEvent as ReactPointerEvent,
} from 'react';
import { useTranslation } from '@/lib/i18n/context';
import {
    minutesOnDate,
    minutesToTime,
    timeToMinutes,
    type Selection,
} from './reservations/time';
import { TimelineChart } from './reservations/timeline-chart';
import { TimelineSelects } from './reservations/timeline-selects';

type TimeRange = {
    starts_at: string;
    ends_at: string;
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

    // Two clicks pick the range: the first sets the start, the second the
    // end. Clicking again once a range is set (or clicking at/before the
    // start) begins a new selection.
    const handleClick = (e: ReactMouseEvent<SVGSVGElement>) => {
        const pos = positionToMinutes(e.clientX);
        setHover(pos);

        if (value.start === null || value.end !== null || pos <= value.start) {
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

    const handlePointerMove = (e: ReactPointerEvent<SVGSVGElement>) => {
        setHover(positionToMinutes(e.clientX));
    };

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
            <div className="mb-3 grid grid-cols-2 gap-3">
                <TimelineSelects
                    value={value}
                    startTimeOptions={startTimeOptions}
                    durationOptions={durationOptions}
                    onStartTimeChange={handleStartTimeChange}
                    onDurationChange={handleDurationChange}
                />
            </div>

            <TimelineChart
                svgRef={svgRef}
                openMin={openMin}
                closeMin={closeMin}
                pastCutoff={pastCutoff}
                peakHours={peakHours}
                blockedRanges={blockedRanges}
                value={value}
                hover={hover}
                onClick={handleClick}
                onPointerMove={handlePointerMove}
                onPointerLeave={() => setHover(null)}
            />

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
