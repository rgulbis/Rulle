import type {
    MouseEvent as ReactMouseEvent,
    PointerEvent as ReactPointerEvent,
    RefObject,
} from 'react';
import type { Selection } from './time';

const WIDTH = 800;
const HEIGHT = 150;
const BAR_TOP = 56;
const BAR_HEIGHT = 50;
const PEAK_BASELINE = BAR_TOP - 6;
const PEAK_HEIGHT = 40;

type Props = {
    svgRef: RefObject<SVGSVGElement | null>;
    openMin: number;
    closeMin: number;
    /** Everything before this (minutes) is in the past and not selectable. */
    pastCutoff: number;
    peakHours: number[];
    blockedRanges: { start: number; end: number }[];
    value: Selection;
    hover: number | null;
    onClick: (e: ReactMouseEvent<SVGSVGElement>) => void;
    onPointerMove: (e: ReactPointerEvent<SVGSVGElement>) => void;
    onPointerLeave: () => void;
};

/** The day's bar: busy-hours curve, blocked slots, hover marker and the picked range. */
export function TimelineChart({
    svgRef,
    openMin,
    closeMin,
    pastCutoff,
    peakHours,
    blockedRanges,
    value,
    hover,
    onClick,
    onPointerMove,
    onPointerLeave,
}: Props) {
    const span = closeMin - openMin;
    const xFor = (minutes: number) => ((minutes - openMin) / span) * WIDTH;

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

    return (
        <svg
            ref={svgRef}
            viewBox={`0 0 ${WIDTH} ${HEIGHT}`}
            className="w-full cursor-pointer select-none"
            onClick={onClick}
            onPointerMove={onPointerMove}
            onPointerLeave={onPointerLeave}
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
                    width={xFor(value.end ?? value.start) - xFor(value.start)}
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
                            hour * 60 < pastCutoff ? 'fill-line' : 'fill-muted'
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
    );
}
