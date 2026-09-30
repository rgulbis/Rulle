import { Head } from '@inertiajs/react';
import ChatShell, {
    ChatGroup,
    useGroupChannelName,
} from '@/components/chat-shell';
import ChatThread, { ChatMessage } from '@/components/chat-thread';
import AppLayout from '@/layouts/app-layout';
import { useTranslation } from '@/lib/i18n/context';

type Props = {
    reservation: {
        id: number;
        starts_at: string;
        ends_at: string;
    };
    messages: ChatMessage[];
    chatGroups: ChatGroup[];
};

export default function ReservationChat({
    reservation,
    messages,
    chatGroups,
}: Props) {
    const { t } = useTranslation();
    const groupName = useGroupChannelName();

    return (
        <AppLayout fullHeight>
            <Head title={t('reservations.groupChat')} />
            <ChatShell active={reservation.id} groups={chatGroups}>
                {/* Keyed by reservation so switching group resets the
                    thread's own state (messages, scroll) instead of reusing
                    the previous group's. */}
                <ChatThread
                    key={reservation.id}
                    channel={`reservation.${reservation.id}.chat`}
                    title={groupName(reservation)}
                    description={t('reservationChat.subtitle')}
                    initialMessages={messages}
                    postUrl={`/reservations/${reservation.id}/chat`}
                />
            </ChatShell>
        </AppLayout>
    );
}
