import { useMemo, useState } from 'react';
import { useTranslation } from '@/lib/i18n/context';

type TimeRange = {
    id: number;
    starts_at: string;
    ends_at: string;
};

type Props = {
    reservations: TimeRange[];
    onSelectDate?: (date: string) => void;
};

function toDateKey(d: Date): string {
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

export default function ReservationCalendar({
    reservations,
    onSelectDate,
}: Props) {
    const { t, intlLocale } = useTranslation();

    // Derived from the locale rather than a hardcoded English list, so it
    // automatically follows along whichever language is picked — including
    // getting the Monday-first order right, which a plain array of labels
    // wouldn't on its own.
    const weekdays = useMemo(() => {
        // A known Monday (2024-01-01) — only its weekday position is used.
        const monday = new Date(2024, 0, 1);

        return Array.from({ length: 7 }, (_, i) => {
            const d = new Date(monday);
            d.setDate(monday.getDate() + i);

            return d.toLocaleDateString(intlLocale, { weekday: 'short' });
        });
    }, [intlLocale]);

    const formatTime = (dateTime: string): string =>
        new Date(dateTime).toLocaleTimeString(intlLocale, {
            hour: '2-digit',
            minute: '2-digit',
        });

    const today = new Date();
    today.setHours(0, 0, 0, 0);

    const [viewDate, setViewDate] = useState(
        new Date(today.getFullYear(), today.getMonth(), 1),
    );
    const [selected, setSelected] = useState<string | null>(null);

    const byDate = new Map<string, TimeRange[]>();
    for (const reservation of reservations) {
        const key = toDateKey(new Date(reservation.starts_at));
        byDate.set(key, [...(byDate.get(key) ?? []), reservation]);
    }

    const year = viewDate.getFullYear();
    const month = viewDate.getMonth();
    const firstOfMonth = new Date(year, month, 1);
    // Monday-first grid: JS getDay() is 0=Sunday, shift so Monday=0.
    const leadingBlanks = (firstOfMonth.getDay() + 6) % 7;
    const daysInMonth = new Date(year, month + 1, 0).getDate();

    const cells: (Date | null)[] = [
        ...Array(leadingBlanks).fill(null),
        ...Array.from(
            { length: daysInMonth },
            (_, i) => new Date(year, month, i + 1),
        ),
    ];

    const selectDay = (date: Date) => {
        const key = toDateKey(date);
        setSelected(key);
        onSelectDate?.(key);
    };

    return (
        <div className="rounded-none border border-gray-200 bg-white p-4 shadow-sm">
            <div className="mb-3 flex items-center justify-between">
                <button
                    type="button"
                    onClick={() => setViewDate(new Date(year, month - 1, 1))}
                    className="px-2 text-sm text-gray-500 hover:text-gray-900"
                    aria-label={t('reservations.previousMonth')}
                >
                    ‹
                </button>
                <p className="text-sm font-semibold text-gray-900">
                    {firstOfMonth.toLocaleDateString(intlLocale, {
                        month: 'long',
                        year: 'numeric',
                    })}
                </p>
                <button
                    type="button"
                    onClick={() => setViewDate(new Date(year, month + 1, 1))}
                    className="px-2 text-sm text-gray-500 hover:text-gray-900"
                    aria-label={t('reservations.nextMonth')}
                >
                    ›
                </button>
            </div>

            <div className="grid grid-cols-7 gap-1 text-center text-xs text-gray-400">
                {weekdays.map((day, i) => (
                    <div key={i} className="py-1">
                        {day}
                    </div>
                ))}
            </div>

            <div className="grid grid-cols-7 gap-1">
                {cells.map((date, i) => {
                    if (!date) {
                        return <div key={i} />;
                    }

                    const key = toDateKey(date);
                    const dayReservations = byDate.get(key) ?? [];
                    const isPast = date < today;
                    const isSelected = selected === key;

                    return (
                        <button
                            type="button"
                            key={key}
                            disabled={isPast}
                            onClick={() => selectDay(date)}
                            className={
                                'flex aspect-square flex-col items-center justify-center gap-0.5 rounded-none border text-sm transition ' +
                                (isPast
                                    ? 'cursor-not-allowed border-transparent text-gray-300'
                                    : isSelected
                                      ? 'border-yellow-500 bg-yellow-100 text-gray-900'
                                      : 'border-transparent text-gray-700 hover:border-gray-300')
                            }
                        >
                            {date.getDate()}
                            {dayReservations.length > 0 && (
                                <span
                                    className={
                                        'h-1.5 w-1.5 rounded-full ' +
                                        (isPast
                                            ? 'bg-gray-300'
                                            : 'bg-amber-500')
                                    }
                                />
                            )}
                        </button>
                    );
                })}
            </div>

            <div className="mt-3 border-t border-gray-100 pt-3">
                {selected && (byDate.get(selected)?.length ?? 0) > 0 ? (
                    <ul className="flex flex-col gap-1 text-sm text-gray-700">
                        {byDate.get(selected)!.map((reservation) => (
                            <li key={reservation.id}>
                                {formatTime(reservation.starts_at)} –{' '}
                                {formatTime(reservation.ends_at)}
                            </li>
                        ))}
                    </ul>
                ) : (
                    <p className="text-sm text-gray-500">
                        {selected
                            ? t('reservations.noneThatDay')
                            : t('reservations.selectDayHint')}
                    </p>
                )}
            </div>
        </div>
    );
}
