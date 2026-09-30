import { Head, Link, router } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';
import {
    Button,
    InputError,
    Label,
    PageHeader,
    PrimaryButton,
    StatusMessage,
    TextInput,
} from '@/components/form-controls';
import ReservationCalendar from '@/components/reservation-calendar';
import ReservationTimeline, {
    minutesToTime,
} from '@/components/reservation-timeline';
import AppLayout, { PageContainer } from '@/layouts/app-layout';
import { useTranslation } from '@/lib/i18n/context';
import { useFormatEuros } from '@/lib/plans';

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
            <p className="text-muted mt-3 text-sm">
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
        <div className="mt-3">
            <TextInput
                value={query}
                onChange={(e) => search(e.target.value)}
                placeholder={t('reservations.searchPlaceholder')}
                className="py-2.5 text-sm"
            />
            {searching && (
                <p className="text-muted mt-1 text-sm">
                    {t('reservations.searching')}
                </p>
            )}
            {results.length > 0 && (
                <ul className="divide-line border-ink bg-paper mt-2 divide-y border-2">
                    {results.map((result) => (
                        <li
                            key={result.id}
                            className="flex items-center justify-between gap-3 px-4 py-2 text-sm"
                        >
                            <span>
                                {result.name}{' '}
                                <span className="text-muted">
                                    {result.email}
                                </span>
                            </span>
                            <button
                                type="button"
                                onClick={() => add(result.id)}
                                className="hover:decoration-accent min-h-11 px-2 font-semibold underline decoration-2 underline-offset-4"
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
    const formatEuros = useFormatEuros();
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

    const statusTone = (key: string) =>
        key === 'reservation-incomplete' ||
        key === 'reservation-cannot-cancel' ||
        key === 'reservation-slot-taken'
            ? 'error'
            : key === 'reservation-cancelled-no-refund'
              ? 'warning'
              : 'info';

    return (
        <AppLayout>
            <Head title={t('nav.reservations')} />
            <PageContainer>
                <PageHeader title={t('reservations.title')} />

                {status === 'reservation-complete' && (
                    <StatusMessage status={t('reservations.statusComplete')} />
                )}
                {status && statusMessages[status] && (
                    <StatusMessage
                        status={statusMessages[status]}
                        tone={statusTone(status)}
                    />
                )}

                <div className="grid gap-10 lg:grid-cols-12">
                    <section className="border-ink bg-paper shadow-hard-lg border-2 p-6 lg:col-span-7 lg:p-8">
                        <h2 className="font-display mb-6 text-4xl font-black uppercase">
                            {t('reservations.reserveATime')}
                        </h2>
                        <form onSubmit={submit} className="flex flex-col gap-6">
                            <div className="flex flex-col gap-2">
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
                                <div className="flex flex-col gap-2">
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
                                    <p className="text-muted text-sm">
                                        {t('reservations.timelineHint', {
                                            min: settings.min_duration_minutes,
                                            max: settings.max_duration_minutes,
                                        })}
                                    </p>
                                </div>
                            )}
                            <InputError message={errors.starts_at} />
                            <InputError message={errors.duration_minutes} />

                            <div className="flex flex-col gap-2">
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
                                    className="max-w-40"
                                />
                                <p className="text-muted text-sm">
                                    {t('reservations.minGroupSizeHint', {
                                        min: settings.min_group_size,
                                    })}
                                </p>
                                <InputError message={errors.group_size} />
                            </div>

                            <div className="border-ink flex flex-wrap items-center justify-between gap-4 border-t-[3px] border-dashed pt-6">
                                <p className="font-display text-4xl font-black">
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
                            </div>
                        </form>
                    </section>

                    <section className="flex flex-col gap-3 lg:col-span-5">
                        <h2 className="font-display text-4xl font-black uppercase">
                            {t('reservations.upcoming')}
                        </h2>
                        <p className="text-muted text-base">
                            {t('reservations.upcomingHint')}
                        </p>
                        <ReservationCalendar
                            reservations={upcoming}
                            onSelectDate={chooseDate}
                        />
                    </section>
                </div>

                <section className="mt-16">
                    <h2 className="font-display mb-6 text-5xl font-black uppercase">
                        {t('reservations.mine')}
                    </h2>
                    {mine.length === 0 ? (
                        <p className="text-muted text-lg">
                            {t('reservations.noneUpcoming')}
                        </p>
                    ) : (
                        <div className="grid gap-6 md:grid-cols-2">
                            {mine.map((reservation) => (
                                <article
                                    key={reservation.id}
                                    className="border-ink bg-paper flex flex-col border-2 p-6"
                                >
                                    <div className="flex items-start justify-between gap-4">
                                        <p className="text-lg font-semibold">
                                            {formatRange(
                                                reservation.starts_at,
                                                reservation.ends_at,
                                            )}
                                        </p>
                                        <span
                                            className={
                                                'shrink-0 px-2.5 py-1 font-mono text-xs font-semibold tracking-[0.08em] uppercase ' +
                                                (reservation.status === 'active'
                                                    ? 'bg-ok-fill text-[#0e0e10]'
                                                    : 'bg-accent text-accent-ink')
                                            }
                                        >
                                            {t(
                                                `reservations.status.${reservation.status}`,
                                            )}
                                        </span>
                                    </div>
                                    <p className="text-muted mt-1 text-base">
                                        {formatEuros(reservation.price_cents)} ·{' '}
                                        {t('reservations.peopleCount', {
                                            count: reservation.group_size,
                                        })}
                                    </p>

                                    {reservation.participants.length > 0 && (
                                        <ul className="divide-line border-line mt-4 flex flex-col divide-y border-y-2">
                                            {reservation.participants.map(
                                                (participant) => (
                                                    <li
                                                        key={participant.id}
                                                        className="flex min-h-11 items-center justify-between gap-3 text-base"
                                                    >
                                                        <span>
                                                            {participant.name}
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
                                                                className="text-danger min-h-11 px-2 text-sm font-semibold underline underline-offset-4"
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

                                    <p className="text-muted mt-3 font-mono text-xs font-semibold tracking-[0.08em] uppercase">
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
                                                reservation.participants.length
                                            }
                                        />
                                    )}

                                    <div className="border-ink mt-5 flex flex-wrap items-center gap-4 border-t-[3px] border-dashed pt-5">
                                        <Link
                                            href={`/reservations/${reservation.id}/chat`}
                                            className="hover:decoration-accent font-semibold underline decoration-2 underline-offset-4"
                                        >
                                            {t('reservations.groupChat')}
                                        </Link>

                                        {reservation.is_owner &&
                                            reservation.status ===
                                                'pending' && (
                                                <Button
                                                    variant="accent"
                                                    onClick={() =>
                                                        resumePayment(
                                                            reservation.id,
                                                        )
                                                    }
                                                >
                                                    {t(
                                                        'reservations.finishPayment',
                                                    )}
                                                </Button>
                                            )}

                                        {reservation.is_owner &&
                                            (reservation.status === 'pending' ||
                                                (reservation.status ===
                                                    'active' &&
                                                    new Date(
                                                        reservation.starts_at,
                                                    ) > new Date())) && (
                                                <Button
                                                    variant="danger"
                                                    onClick={() =>
                                                        cancelReservation(
                                                            reservation.id,
                                                        )
                                                    }
                                                >
                                                    {t('reservations.cancel')}
                                                </Button>
                                            )}
                                    </div>
                                </article>
                            ))}
                        </div>
                    )}
                </section>
            </PageContainer>
        </AppLayout>
    );
}
