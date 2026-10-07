import { Head } from '@inertiajs/react';
import { PageHeader, StatusMessage } from '@/components/form-controls';
import { BookingSection } from '@/components/reservations/booking-section';
import { InvitationsSection } from '@/components/reservations/invitations-section';
import { MyReservationsSection } from '@/components/reservations/my-reservations-section';
import type {
    Invitation,
    MyReservation,
    Settings,
    TimeRange,
} from '@/components/reservations/types';
import AppLayout, { PageContainer } from '@/layouts/app-layout';
import { useTranslation } from '@/lib/i18n/context';

type Props = {
    settings: Settings;
    peakHours: number[];
    upcoming: TimeRange[];
    mine: MyReservation[];
    invitations: Invitation[];
    status?: string;
};

export default function ReservationsIndex({
    settings,
    peakHours,
    upcoming,
    mine,
    invitations,
    status,
}: Props) {
    const { t } = useTranslation();

    const statusMessages: Record<string, string | undefined> = {
        'reservation-incomplete': t('reservations.statusIncomplete'),
        'reservation-cancelled': t('reservations.statusCancelled'),
        'reservation-cancelled-refunded': t(
            'reservations.statusCancelledRefunded',
        ),
        'reservation-cancelled-refund-pending': t(
            'reservations.statusCancelledRefundPending',
        ),
        'reservation-cancelled-no-payment': t(
            'reservations.statusCancelledNoPayment',
        ),
        'reservation-cancelled-no-refund': t(
            'reservations.statusCancelledNoRefund',
        ),
        'reservation-cannot-cancel': t('reservations.statusCannotCancel'),
        'reservation-slot-taken': t('reservations.statusSlotTaken'),
        'reservation-left': t('reservations.statusLeft'),
        'participant-invited': t('reservations.statusInvited'),
        'invitation-accepted': t('reservations.statusInvitationAccepted'),
        'invitation-declined': t('reservations.statusInvitationDeclined'),
        'invitation-full': t('reservations.statusInvitationFull'),
        'invitation-unavailable': t('reservations.statusInvitationUnavailable'),
    };

    const statusTone = (key: string) =>
        key === 'reservation-incomplete' ||
        key === 'reservation-cannot-cancel' ||
        key === 'reservation-slot-taken' ||
        key === 'invitation-full' ||
        key === 'invitation-unavailable'
            ? 'error'
            : key === 'reservation-cancelled-no-refund' ||
                key === 'reservation-cancelled-refund-pending'
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

                <BookingSection
                    settings={settings}
                    peakHours={peakHours}
                    upcoming={upcoming}
                />
                <InvitationsSection invitations={invitations} />
                <MyReservationsSection mine={mine} />
            </PageContainer>
        </AppLayout>
    );
}
