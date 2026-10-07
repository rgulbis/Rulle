import { Link, router } from '@inertiajs/react';
import { Button } from '@/components/form-controls';
import { useTranslation } from '@/lib/i18n/context';
import { useFormatEuros } from '@/lib/plans';
import { AddParticipant } from './add-participant';
import { acceptedCount, type MyReservation } from './types';
import { useFormatRange } from './use-format-range';

/** The viewer's own and joined reservations, each with its actions. */
export function MyReservationsSection({ mine }: { mine: MyReservation[] }) {
    const { t } = useTranslation();

    return (
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
                        <ReservationCard
                            key={reservation.id}
                            reservation={reservation}
                        />
                    ))}
                </div>
            )}
        </section>
    );
}

function ReservationCard({ reservation }: { reservation: MyReservation }) {
    const { t } = useTranslation();
    const formatEuros = useFormatEuros();
    const formatRange = useFormatRange();

    const removeParticipant = (userId: number) => {
        router.delete(
            `/reservations/${reservation.id}/participants/${userId}`,
            { preserveScroll: true },
        );
    };

    const cancel = () => {
        if (confirm(t('reservations.confirmCancel'))) {
            router.delete(`/reservations/${reservation.id}`);
        }
    };

    const leave = () => {
        if (confirm(t('reservations.confirmLeave'))) {
            router.post(`/reservations/${reservation.id}/leave`);
        }
    };

    const resumePayment = () => {
        router.post(`/reservations/${reservation.id}/resume`);
    };

    return (
        <article className="border-ink bg-paper flex flex-col border-2 p-6">
            <div className="flex items-start justify-between gap-4">
                <p className="text-lg font-semibold">
                    {formatRange(reservation.starts_at, reservation.ends_at)}
                </p>
                <span
                    className={
                        'shrink-0 px-2.5 py-1 font-mono text-xs font-semibold tracking-[0.08em] uppercase ' +
                        (reservation.status === 'active'
                            ? 'bg-ok-fill text-[#0e0e10]'
                            : 'bg-accent text-accent-ink')
                    }
                >
                    {t(`reservations.status.${reservation.status}`)}
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
                    {reservation.participants.map((participant) => (
                        <li
                            key={participant.id}
                            className="flex min-h-11 items-center justify-between gap-3 text-base"
                        >
                            <span>
                                {participant.name}
                                {participant.status !== 'accepted' && (
                                    <span className="text-muted ml-2 font-mono text-xs font-semibold tracking-[0.08em] uppercase">
                                        {t(
                                            `reservations.participantStatus.${participant.status}`,
                                        )}
                                    </span>
                                )}
                            </span>
                            {reservation.is_owner &&
                                participant.status !== 'declined' && (
                                    <button
                                        type="button"
                                        onClick={() =>
                                            removeParticipant(participant.id)
                                        }
                                        className="text-danger min-h-11 px-2 text-sm font-semibold underline underline-offset-4"
                                    >
                                        {t(
                                            participant.status === 'invited'
                                                ? 'reservations.withdrawInvitation'
                                                : 'reservations.remove',
                                        )}
                                    </button>
                                )}
                        </li>
                    ))}
                </ul>
            )}

            <p className="text-muted mt-3 font-mono text-xs font-semibold tracking-[0.08em] uppercase">
                {t('reservations.namedOf', {
                    named: acceptedCount(reservation) + 1,
                    total: reservation.group_size,
                })}
            </p>

            {/* Invitations and the group chat only exist once the
                reservation is paid; the server refuses both before. */}
            {reservation.is_owner && reservation.status === 'active' && (
                <AddParticipant
                    reservationId={reservation.id}
                    remainingCapacity={
                        reservation.group_size - 1 - acceptedCount(reservation)
                    }
                />
            )}

            <div className="border-ink mt-5 flex flex-wrap items-center gap-4 border-t-[3px] border-dashed pt-5">
                {reservation.status === 'active' && (
                    <Link
                        href={`/reservations/${reservation.id}/chat`}
                        className="hover:decoration-accent font-semibold underline decoration-2 underline-offset-4"
                    >
                        {t('reservations.groupChat')}
                    </Link>
                )}

                {reservation.is_owner && reservation.status === 'pending' && (
                    <Button variant="accent" onClick={resumePayment}>
                        {t('reservations.finishPayment')}
                    </Button>
                )}

                {reservation.is_owner &&
                    (reservation.status === 'pending' ||
                        (reservation.status === 'active' &&
                            new Date(reservation.starts_at) > new Date())) && (
                        <Button variant="danger" onClick={cancel}>
                            {t('reservations.cancel')}
                        </Button>
                    )}

                {!reservation.is_owner && (
                    <Button variant="danger" onClick={leave}>
                        {t('reservations.leave')}
                    </Button>
                )}
            </div>
        </article>
    );
}
