import { Head, router } from '@inertiajs/react';
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

function formatRange(startsAt: string, endsAt: string) {
    const start = new Date(startsAt);
    const end = new Date(endsAt);

    return `${start.toLocaleDateString()} ${start.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })} – ${end.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}`;
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
    const [query, setQuery] = useState('');
    const [results, setResults] = useState<Participant[]>([]);
    const [searching, setSearching] = useState(false);

    if (remainingCapacity <= 0) {
        return (
            <p className="mt-2 text-xs text-gray-400">
                This reservation's group size is full — remove someone or make a
                new reservation for more people.
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
                placeholder="Search by name or email to add a friend"
                className="text-sm"
            />
            {searching && (
                <p className="mt-1 text-xs text-gray-400">Searching…</p>
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
                                Add
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
        if (confirm('Cancel this reservation?')) {
            router.get(`/reservations/${reservationId}/cancel`);
        }
    };

    const resumePayment = (reservationId: number) => {
        router.post(`/reservations/${reservationId}/resume`);
    };

    return (
        <AppLayout>
            <Head title="Reservations" />
            <div className="p-6">
                <div className="mx-auto max-w-3xl">
                    <h1 className="mb-6 text-xl font-semibold text-gray-900">
                        Reserve the park
                    </h1>

                    {status === 'reservation-complete' && (
                        <StatusMessage status="Reservation confirmed — the park is yours for that time." />
                    )}
                    {status === 'reservation-incomplete' && (
                        <p className="mb-4 rounded-none border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
                            That checkout wasn't completed, so nothing was
                            reserved.
                        </p>
                    )}
                    {status === 'reservation-cancelled' && (
                        <p className="mb-4 rounded-none border border-gray-200 bg-gray-50 px-3 py-2 text-sm text-gray-600">
                            Reservation cancelled.
                        </p>
                    )}
                    {status === 'reservation-cancelled-refunded' && (
                        <p className="mb-4 rounded-none border border-gray-200 bg-gray-50 px-3 py-2 text-sm text-gray-600">
                            Reservation cancelled and refunded.
                        </p>
                    )}
                    {status === 'reservation-cancelled-no-refund' && (
                        <p className="mb-4 rounded-none border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-700">
                            Reservation cancelled, but it was too close to the
                            start time to be refunded.
                        </p>
                    )}
                    {status === 'reservation-cannot-cancel' && (
                        <p className="mb-4 rounded-none border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
                            That reservation has already started and can no
                            longer be cancelled.
                        </p>
                    )}
                    {status === 'reservation-slot-taken' && (
                        <p className="mb-4 rounded-none border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
                            Someone else booked that time first while you were
                            paying — you've been refunded and the reservation
                            was cancelled. Pick another time.
                        </p>
                    )}

                    <div className="mb-8 rounded-none border border-gray-200 bg-white p-6 shadow-sm">
                        <h2 className="mb-4 text-base font-semibold text-gray-900">
                            Reserve a time
                        </h2>
                        <form onSubmit={submit} className="flex flex-col gap-4">
                            <div className="flex flex-col gap-1">
                                <Label htmlFor="starts_at_date">Date</Label>
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
                                    <Label>Time</Label>
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
                                        {settings.min_duration_minutes}–
                                        {settings.max_duration_minutes} minutes,
                                        in 15-minute steps. The dotted line
                                        shows typically busy hours; the red
                                        block is already reserved.
                                    </p>
                                </div>
                            )}
                            <InputError message={errors.starts_at} />
                            <InputError message={errors.duration_minutes} />

                            <div className="flex flex-col gap-1">
                                <Label htmlFor="group_size">
                                    Group size (people)
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
                                    Minimum {settings.min_group_size} people —
                                    priced per person, per hour.
                                </p>
                                <InputError message={errors.group_size} />
                            </div>

                            <p className="text-sm font-medium text-gray-900">
                                Price:{' '}
                                {formatEuros(
                                    priceFor(
                                        durationMinutes,
                                        groupSize,
                                        settings.price_cents_per_person_per_hour,
                                    ),
                                )}
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
                                Reserve and pay
                            </PrimaryButton>
                        </form>
                    </div>

                    <div className="mb-8">
                        <h2 className="mb-3 text-base font-semibold text-gray-900">
                            Upcoming reservations
                        </h2>
                        <p className="mb-3 text-sm text-gray-500">
                            The park is privately reserved during these times —
                            everyone else's entry is paused until they end.
                            Click a day to see its reserved times, or to book
                            that day above.
                        </p>
                        <ReservationCalendar
                            reservations={upcoming}
                            onSelectDate={chooseDate}
                        />
                    </div>

                    <div>
                        <h2 className="mb-3 text-base font-semibold text-gray-900">
                            My reservations
                        </h2>
                        {mine.length === 0 ? (
                            <p className="text-sm text-gray-500">
                                You don't have any upcoming reservations.
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
                                                {reservation.status}
                                            </span>
                                        </div>
                                        <p className="mt-1 text-sm text-gray-500">
                                            {formatEuros(
                                                reservation.price_cents,
                                            )}{' '}
                                            · {reservation.group_size} people
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
                                                                Remove
                                                            </button>
                                                        </li>
                                                    ),
                                                )}
                                            </ul>
                                        )}

                                        <p className="mt-2 text-xs text-gray-400">
                                            {reservation.participants.length +
                                                1}{' '}
                                            of {reservation.group_size} named
                                        </p>

                                        <AddParticipant
                                            reservationId={reservation.id}
                                            remainingCapacity={
                                                reservation.group_size -
                                                1 -
                                                reservation.participants.length
                                            }
                                        />

                                        {reservation.status === 'pending' && (
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
                                                    Finish payment
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
                                                    Cancel reservation
                                                </button>
                                            </div>
                                        )}

                                        {reservation.status === 'active' &&
                                            new Date(reservation.starts_at) >
                                                new Date() && (
                                                <button
                                                    type="button"
                                                    onClick={() =>
                                                        cancelReservation(
                                                            reservation.id,
                                                        )
                                                    }
                                                    className="mt-3 text-sm font-medium text-red-600 hover:text-red-500"
                                                >
                                                    Cancel reservation
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
