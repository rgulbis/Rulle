import { router } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';
import {
    InputError,
    Label,
    PrimaryButton,
    TextInput,
} from '@/components/form-controls';
import { CalendarIcon } from '@/components/icons';
import ReservationCalendar from '@/components/reservation-calendar';
import ReservationTimeline from '@/components/reservation-timeline';
import { useTranslation } from '@/lib/i18n/context';
import { useFormatEuros } from '@/lib/plans';
import { minutesToTime } from './time';
import type { Settings, TimeRange } from './types';

type Props = {
    settings: Settings;
    peakHours: number[];
    upcoming: TimeRange[];
};

function priceFor(
    minutes: number,
    groupSize: number,
    pricePerPersonPerHourCents: number,
) {
    return Math.ceil((minutes / 60) * pricePerPersonPerHourCents * groupSize);
}

/**
 * The "reserve a time" form beside the calendar of already-booked slots. They
 * share the chosen date, so one component owns both.
 */
/** Mirrors the server's 12-month booking horizon (Reservation::MAX_MONTHS_AHEAD). */
function maxBookableDate(): string {
    const limit = new Date();
    limit.setMonth(limit.getMonth() + 12);

    return limit.toISOString().split('T')[0];
}

export function BookingSection({ settings, peakHours, upcoming }: Props) {
    const { t } = useTranslation();
    const formatEuros = useFormatEuros();
    const [date, setDate] = useState('');
    const [selection, setSelection] = useState<{
        start: number | null;
        end: number | null;
    }>({ start: null, end: null });
    // '' while the field is being edited (e.g. cleared to retype a value) -
    // coercing that straight to a number would snap the box to showing "0"
    // on every clear, which made retyping a value fiddly. It's clamped back
    // to a valid size on blur instead, and only ever sent to the server as
    // a real number (see submit()).
    const [groupSize, setGroupSize] = useState<number | ''>(
        settings.min_group_size,
    );
    const [errors, setErrors] = useState<{
        starts_at?: string;
        duration_minutes?: string;
        group_size?: string;
    }>({});
    const [submitting, setSubmitting] = useState(false);

    const clampGroupSize = (size: number) =>
        Math.min(
            Math.max(size, settings.min_group_size),
            settings.max_group_size,
        );

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
                group_size:
                    groupSize === ''
                        ? settings.min_group_size
                        : clampGroupSize(groupSize),
            },
            {
                onError: (formErrors) => setErrors(formErrors),
                onFinish: () => setSubmitting(false),
            },
        );
    };

    return (
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
                        {/* iOS Safari's native date control ignores
                        `width: 100%` unless its own appearance is
                        stripped - clipping the overflow instead (an
                        earlier attempt here) just clipped the box's
                        own border along with it. This is the same
                        fix already confirmed working for the
                        duration <Select> below. */}
                        <div className="relative">
                            <TextInput
                                id="starts_at_date"
                                type="date"
                                value={date}
                                min={new Date().toISOString().split('T')[0]}
                                max={maxBookableDate()}
                                onChange={(e) => chooseDate(e.target.value)}
                                className="appearance-none pr-11 [&::-webkit-calendar-picker-indicator]:hidden"
                            />
                            <CalendarIcon
                                aria-hidden="true"
                                className="pointer-events-none absolute top-1/2 right-4 -translate-y-1/2"
                            />
                        </div>
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
                            max={settings.max_group_size}
                            value={groupSize}
                            onChange={(e) => {
                                const raw = e.target.value;
                                setGroupSize(raw === '' ? '' : Number(raw));
                            }}
                            onBlur={() =>
                                setGroupSize((current) =>
                                    clampGroupSize(
                                        current === ''
                                            ? settings.min_group_size
                                            : current,
                                    ),
                                )
                            }
                            className="max-w-40"
                        />
                        <p className="text-muted text-sm">
                            {t('reservations.groupSizeHint', {
                                min: settings.min_group_size,
                                max: settings.max_group_size,
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
                                        groupSize === '' ? 0 : groupSize,
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
                                selection.end === null ||
                                groupSize === ''
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
    );
}
