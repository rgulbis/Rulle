import { Head, Link, router } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';
import {
    InputError,
    Label,
    PrimaryButton,
    StatusMessage,
    TextInput,
} from '@/components/form-controls';
import ReservationCalendar from '@/components/reservation-calendar';
import ReservationTimeline, {
    minutesToTime,
} from '@/components/reservation-timeline';
import AppLayout from '@/layouts/app-layout';
import { useTranslation } from '@/lib/i18n/context';

type Settings = {
    price_cents_per_person_per_hour: number;
    min_group_size: number;
    min_duration_minutes: number;
    max_duration_minutes: number;
    opening_time: string;
    closing_time: string;
};

type TimeRange = {
    id: number;
    starts_at: string;
    ends_at: string;
};

type Participant = {
    id: number;
    name: string;
    email: string;
};

type MyReservation = TimeRange & {
    group_size: number;
    price_cents: number;
    status: 'pending' | 'active' | 'cancelled';
    is_owner: boolean;
    participants: Participant[];
};

type Props = {
    settings: Settings;
    peakHours: number[];
    upcoming: TimeRange[];
    mine: MyReservation[];
    status?: string;
};

function formatEuros(cents: number) {
    return (cents / 100).toFixed(2) + ' €';
}

function priceFor(
    minutes: number,
    groupSize: number,
    pricePerPersonPerHourCents: number,
) {
    return Math.ceil((minutes / 60) * pricePerPersonPerHourCents * groupSize);
}

function AddParticipant({
    reservationId,
    remainingCapacity,
}: {
    reservationId: number;
    remainingCapacity: number;
}) {
    const { t } = useTranslation();
    const [query, setQuery] = useState('');
    const [results, setResults] = useState<Participant[]>([]);
    const [searching, setSearching] = useState(false);

    if (remainingCapacity <= 0) {
        return (
            <p className="mt-2 text-xs text-gray-400">
                {t('reservations.groupFull')}
            </p>
        );
    }

    const search = async (value: string) => {
        setQuery(value);

        if (value.trim().length < 2) {
            setResults([]);
            return;
        }

        setSearching(true);

        try {
            const response = await fetch(
                `/reservations/users/search?q=${encodeURIComponent(value)}`,
                { headers: { Accept: 'application/json' } },
            );
            setResults(response.ok ? await response.json() : []);
        } finally {
            setSearching(false);
        }
    };

    const add = (userId: number) => {
        router.post(
            `/reservations/${reservationId}/participants`,
            { user_id: userId },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setQuery('');
                    setResults([]);
                },
            },
        );
    };

    return (
        <div className="mt-2">
            <TextInput
                value={query}
                onChange={(e) => search(e.target.value)}
                placeholder={t('reservations.searchPlaceholder')}
                className="text-sm"
            />
            {searching && (
                <p className="mt-1 text-xs text-gray-400">
                    {t('reservations.searching')}
                </p>
            )}
            {results.length > 0 && (
                <ul className="mt-1 divide-y divide-gray-100 border border-gray-200">
                    {results.map((result) => (
                        <li
                            key={result.id}
                            className="flex items-center justify-between px-3 py-2 text-sm"
                        >
                            <span>
                                {result.name}{' '}
                                <span className="text-gray-400">
                                    {result.email}
                                </span>
                            </span>
                            <button
                                type="button"
                                onClick={() => add(result.id)}
                                className="text-sm font-semibold text-yellow-700 hover:text-yellow-600"
                            >
                                {t('reservations.add')}
                            </button>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}

export default function ReservationsIndex({
    settings,
    peakHours,
    upcoming,
    mine,
    status,
}: Props) {
    const { t, intlLocale } = useTranslation();
    const [date, setDate] = useState('');
    const [selection, setSelection] = useState<{
        start: number | null;
        end: number | null;
    }>({ start: null, end: null });
    const [groupSize, setGroupSize] = useState(settings.min_group_size);
    const [errors, setErrors] = useState<{
        starts_at?: string;
        duration_minutes?: string;
        group_size?: string;
    }>({});
    const [submitting, setSubmitting] = useState(false);

    const durationMinutes =
        selection.start !== null && selection.end !== null
            ? selection.end - selection.start
            : 0;

    const formatRange = (startsAt: string, endsAt: string) => {
        const start = new Date(startsAt);
        const end = new Date(endsAt);

        return `${start.toLocaleDateString(intlLocale)} ${start.toLocaleTimeString(intlLocale, { hour: '2-digit', minute: '2-digit' })} – ${end.toLocaleTimeString(intlLocale, { hour: '2-digit', minute: '2-digit' })}`;
    };

    const chooseDate = (newDate: string) => {
        setDate(newDate);
        setSelection({ start: null, end: null });
    };

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        setSubmitting(true);

        router.post(
            '/reservations',
            {
                starts_at: `${date} ${minutesToTime(selection.start ?? 0)}`,
                duration_minutes: durationMinutes,
                group_size: groupSize,
            },
            {
                onError: (formErrors) => setErrors(formErrors),
                onFinish: () => setSubmitting(false),
            },
        );
    };

    const removeParticipant = (reservationId: number, userId: number) => {
        router.delete(`/reservations/${reservationId}/participants/${userId}`, {
            preserveScroll: true,
        });
    };

    const cancelReservation = (reservationId: number) => {
        if (confirm(t('reservations.confirmCancel'))) {
            router.get(`/reservations/${reservationId}/cancel`);
        }
    };

    const resumePayment = (reservationId: number) => {
        router.post(`/reservations/${reservationId}/resume`);
    };

    const statusMessages: Record<string, string | undefined> = {
        'reservation-incomplete': t('reservations.statusIncomplete'),
        'reservation-cancelled': t('reservations.statusCancelled'),
        'reservation-cancelled-refunded': t(
            'reservations.statusCancelledRefunded',
        ),
        'reservation-cancelled-no-refund': t(
            'reservations.statusCancelledNoRefund',
        ),
        'reservation-cannot-cancel': t('reservations.statusCannotCancel'),
        'reservation-slot-taken': t('reservations.statusSlotTaken'),
    };

    return (
        <AppLayout>
            <Head title={t('nav.reservations')} />
            <div className="p-6">
                <div className="mx-auto max-w-3xl">
                    <h1 className="mb-6 text-xl font-semibold text-gray-900">
                        {t('reservations.title')}
                    </h1>

                    {status === 'reservation-complete' && (
                        <StatusMessage
                            status={t('reservations.statusComplete')}
                        />
                    )}
                    {status && statusMessages[status] && (
                        <p
                            className={
                                'mb-4 rounded-none border px-3 py-2 text-sm ' +
                                (status === 'reservation-incomplete' ||
                                status === 'reservation-cannot-cancel' ||
                                status === 'reservation-slot-taken'
                                    ? 'border-red-200 bg-red-50 text-red-700'
                                    : status ===
                                        'reservation-cancelled-no-refund'
                                      ? 'border-amber-200 bg-amber-50 text-amber-700'
                                      : 'border-gray-200 bg-gray-50 text-gray-600')
                            }
                        >
                            {statusMessages[status]}
                        </p>
                    )}

                    <div className="mb-8 rounded-none border border-gray-200 bg-white p-6 shadow-sm">
                        <h2 className="mb-4 text-base font-semibold text-gray-900">
                            {t('reservations.reserveATime')}
                        </h2>
                        <form onSubmit={submit} className="flex flex-col gap-4">
                            <div className="flex flex-col gap-1">
                                <Label htmlFor="starts_at_date">
                                    {t('reservations.date')}
                                </Label>
                                <TextInput
                                    id="starts_at_date"
                                    type="date"
                                    value={date}
                                    min={new Date().toISOString().split('T')[0]}
                                    onChange={(e) => chooseDate(e.target.value)}
                                />
                            </div>

                            {date && (
                                <div className="flex flex-col gap-1">
                                    <Label>{t('reservations.time')}</Label>
                                    <ReservationTimeline
                                        date={date}
                                        openingTime={settings.opening_time}
                                        closingTime={settings.closing_time}
                                        peakHours={peakHours}
                                        existingReservations={upcoming}
                                        minDurationMinutes={
                                            settings.min_duration_minutes
                                        }
                                        maxDurationMinutes={
                                            settings.max_duration_minutes
                                        }
                                        value={selection}
                                        onChange={setSelection}
                                    />
                                    <p className="text-xs text-gray-400">
                                        {t('reservations.timelineHint', {
                                            min: settings.min_duration_minutes,
                                            max: settings.max_duration_minutes,
                                        })}
                                    </p>
                                </div>
                            )}
                            <InputError message={errors.starts_at} />
                            <InputError message={errors.duration_minutes} />

                            <div className="flex flex-col gap-1">
                                <Label htmlFor="group_size">
                                    {t('reservations.groupSize')}
                                </Label>
                                <TextInput
                                    id="group_size"
                                    type="number"
                                    min={settings.min_group_size}
                                    value={groupSize}
                                    onChange={(e) =>
                                        setGroupSize(Number(e.target.value))
                                    }
                                />
                                <p className="text-xs text-gray-400">
                                    {t('reservations.minGroupSizeHint', {
                                        min: settings.min_group_size,
                                    })}
                                </p>
                                <InputError message={errors.group_size} />
                            </div>

                            <p className="text-sm font-medium text-gray-900">
                                {t('reservations.price', {
                                    amount: formatEuros(
                                        priceFor(
                                            durationMinutes,
                                            groupSize,
                                            settings.price_cents_per_person_per_hour,
                                        ),
                                    ),
                                })}
                            </p>

                            <PrimaryButton
                                type="submit"
                                disabled={
                                    submitting ||
                                    !date ||
                                    selection.start === null ||
                                    selection.end === null
                                }
                            >
                                {t('reservations.reserveAndPay')}
                            </PrimaryButton>
                        </form>
                    </div>

                    <div className="mb-8">
                        <h2 className="mb-3 text-base font-semibold text-gray-900">
                            {t('reservations.upcoming')}
                        </h2>
                        <p className="mb-3 text-sm text-gray-500">
                            {t('reservations.upcomingHint')}
                        </p>
                        <ReservationCalendar
                            reservations={upcoming}
                            onSelectDate={chooseDate}
                        />
                    </div>

                    <div>
                        <h2 className="mb-3 text-base font-semibold text-gray-900">
                            {t('reservations.mine')}
                        </h2>
                        {mine.length === 0 ? (
                            <p className="text-sm text-gray-500">
                                {t('reservations.noneUpcoming')}
                            </p>
                        ) : (
                            <div className="flex flex-col gap-4">
                                {mine.map((reservation) => (
                                    <div
                                        key={reservation.id}
                                        className="rounded-none border border-gray-200 bg-white p-4 shadow-sm"
                                    >
                                        <div className="flex items-center justify-between">
                                            <p className="text-sm font-medium text-gray-900">
                                                {formatRange(
                                                    reservation.starts_at,
                                                    reservation.ends_at,
                                                )}
                                            </p>
                                            <span
                                                className={
                                                    'text-xs font-semibold ' +
                                                    (reservation.status ===
                                                    'active'
                                                        ? 'text-green-600'
                                                        : 'text-amber-600')
                                                }
                                            >
                                                {t(
                                                    `reservations.status.${reservation.status}`,
                                                )}
                                            </span>
                                        </div>
                                        <p className="mt-1 text-sm text-gray-500">
                                            {formatEuros(
                                                reservation.price_cents,
                                            )}{' '}
                                            ·{' '}
                                            {t('reservations.peopleCount', {
                                                count: reservation.group_size,
                                            })}
                                        </p>

                                        {reservation.participants.length >
                                            0 && (
                                            <ul className="mt-3 flex flex-col gap-1">
                                                {reservation.participants.map(
                                                    (participant) => (
                                                        <li
                                                            key={participant.id}
                                                            className="flex items-center justify-between text-sm text-gray-700"
                                                        >
                                                            <span>
                                                                {
                                                                    participant.name
                                                                }
                                                            </span>
                                                            {reservation.is_owner && (
                                                                <button
                                                                    type="button"
                                                                    onClick={() =>
                                                                        removeParticipant(
                                                                            reservation.id,
                                                                            participant.id,
                                                                        )
                                                                    }
                                                                    className="text-xs font-medium text-red-600 hover:text-red-500"
                                                                >
                                                                    {t(
                                                                        'reservations.remove',
                                                                    )}
                                                                </button>
                                                            )}
                                                        </li>
                                                    ),
                                                )}
                                            </ul>
                                        )}

                                        <p className="mt-2 text-xs text-gray-400">
                                            {t('reservations.namedOf', {
                                                named:
                                                    reservation.participants
                                                        .length + 1,
                                                total: reservation.group_size,
                                            })}
                                        </p>

                                        {reservation.is_owner && (
                                            <AddParticipant
                                                reservationId={reservation.id}
                                                remainingCapacity={
                                                    reservation.group_size -
                                                    1 -
                                                    reservation.participants
                                                        .length
                                                }
                                            />
                                        )}

                                        <Link
                                            href={`/reservations/${reservation.id}/chat`}
                                            className="mt-3 inline-block text-sm font-semibold text-yellow-700 hover:text-yellow-600"
                                        >
                                            {t('reservations.groupChat')}
                                        </Link>

                                        {reservation.is_owner &&
                                            reservation.status ===
                                                'pending' && (
                                                <div className="mt-3 flex items-center gap-4">
                                                    <button
                                                        type="button"
                                                        onClick={() =>
                                                            resumePayment(
                                                                reservation.id,
                                                            )
                                                        }
                                                        className="text-sm font-semibold text-yellow-700 hover:text-yellow-600"
                                                    >
                                                        {t(
                                                            'reservations.finishPayment',
                                                        )}
                                                    </button>
                                                    <button
                                                        type="button"
                                                        onClick={() =>
                                                            cancelReservation(
                                                                reservation.id,
                                                            )
                                                        }
                                                        className="text-sm font-medium text-red-600 hover:text-red-500"
                                                    >
                                                        {t(
                                                            'reservations.cancel',
                                                        )}
                                                    </button>
                                                </div>
                                            )}

                                        {reservation.is_owner &&
                                            reservation.status === 'active' &&
                                            new Date(reservation.starts_at) >
                                                new Date() && (
                                                <button
                                                    type="button"
                                                    onClick={() =>
                                                        cancelReservation(
                                                            reservation.id,
                                                        )
                                                    }
                                                    className="mt-3 block text-sm font-medium text-red-600 hover:text-red-500"
                                                >
                                                    {t('reservations.cancel')}
                                                </button>
                                            )}
                                    </div>
                                ))}
                            </div>
                        )}
                    </div>
                </div>
            </div>
        </AppLayout>
    );
}
