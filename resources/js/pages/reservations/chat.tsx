import { Head } from '@inertiajs/react';
import ChatShell, {
    ChatGroup,
    useGroupChannelName,
} from '@/components/chat-shell';
import type { ChatMessage, MutedUser } from '@/components/chat/types';
import ChatThread from '@/components/chat-thread';
import AppLayout from '@/layouts/app-layout';
import { useTranslation } from '@/lib/i18n/context';

type Props = {
    reservation: {
        id: number;
        starts_at: string;
        ends_at: string;
    };
    messages: ChatMessage[];
    pinned: ChatMessage[];
    chatGroups: ChatGroup[];
    readOnly: boolean;
    canModerate: boolean;
    muted: boolean;
    mutedUntil: string | null;
    mutedParticipants: MutedUser[];
};

export default function ReservationChat({
    reservation,
    messages,
    pinned,
    chatGroups,
    readOnly,
    canModerate,
    muted,
    mutedUntil,
    mutedParticipants,
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
                    initialPinned={pinned}
                    postUrl={`/reservations/${reservation.id}/chat`}
                    muted={muted}
                    readOnly={readOnly}
                    mutedUntil={mutedUntil}
                    mutedUsers={mutedParticipants}
                    moderation={
                        canModerate
                            ? {
                                  deleteUrl: (id) =>
                                      `/reservations/${reservation.id}/chat/${id}`,
                                  muteUrl: (userId) =>
                                      `/reservations/${reservation.id}/chat/users/${userId}/mute`,
                                  unmuteUrl: (userId) =>
                                      `/reservations/${reservation.id}/chat/users/${userId}/unmute`,
                                  pinUrl: (id) =>
                                      `/reservations/${reservation.id}/chat/${id}/pin`,
                              }
                            : undefined
                    }
                />
            </ChatShell>
        </AppLayout>
    );
}
