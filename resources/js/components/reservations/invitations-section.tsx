import { router } from '@inertiajs/react';
import { Button, PrimaryButton } from '@/components/form-controls';
import { useTranslation } from '@/lib/i18n/context';
import type { Invitation } from './types';
import { useFormatRange } from './use-format-range';

/** Reservations other people invited the viewer to, awaiting an answer. */
export function InvitationsSection({
    invitations,
}: {
    invitations: Invitation[];
}) {
    const { t } = useTranslation();
    const formatRange = useFormatRange();

    if (invitations.length === 0) {
        return null;
    }

    const answer = (reservationId: number, choice: 'accept' | 'decline') => {
        router.post(
            `/reservations/${reservationId}/invitation/${choice}`,
            {},
            { preserveScroll: true },
        );
    };

    return (
        <section className="mt-16">
            <h2 className="font-display mb-6 text-5xl font-black uppercase">
                {t('reservations.invitations')}
            </h2>
            <div className="grid gap-6 md:grid-cols-2">
                {invitations.map((invitation) => (
                    <article
                        key={invitation.id}
                        className="border-ink bg-paper shadow-hard flex flex-col border-2 p-6"
                    >
                        <p className="text-lg font-semibold">
                            {formatRange(
                                invitation.starts_at,
                                invitation.ends_at,
                            )}
                        </p>
                        <p className="text-muted mt-1 text-base">
                            {t('reservations.invitedBy', {
                                name: invitation.owner_name,
                            })}{' '}
                            ·{' '}
                            {t('reservations.peopleCount', {
                                count: invitation.group_size,
                            })}
                        </p>
                        <div className="mt-5 flex flex-wrap items-center gap-4">
                            <PrimaryButton
                                type="button"
                                onClick={() => answer(invitation.id, 'accept')}
                            >
                                {t('reservations.accept')}
                            </PrimaryButton>
                            <Button
                                variant="secondary"
                                onClick={() => answer(invitation.id, 'decline')}
                            >
                                {t('reservations.decline')}
                            </Button>
                        </div>
                    </article>
                ))}
            </div>
        </section>
    );
}
