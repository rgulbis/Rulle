import { Head } from '@inertiajs/react';
import ChatShell, { ChatGroup } from '@/components/chat-shell';
import ChatThread, { ChatMessage, SlowMode } from '@/components/chat-thread';
import AppLayout from '@/layouts/app-layout';
import { useTranslation } from '@/lib/i18n/context';

type Props = {
    messages: ChatMessage[];
    pinned: ChatMessage[];
    slowMode: SlowMode;
    canModerate: boolean;
    muted: boolean;
    mutedUntil: string | null;
    chatGroups: ChatGroup[];
};

export default function ChatIndex({
    messages,
    pinned,
    slowMode,
    canModerate,
    muted,
    mutedUntil,
    chatGroups,
}: Props) {
    const { t } = useTranslation();

    return (
        <AppLayout fullHeight>
            <Head title={t('chat.title')} />
            <ChatShell active="general" groups={chatGroups}>
                <ChatThread
                    channel="chat"
                    title={t('chat.general')}
                    description={t('chat.subtitle')}
                    initialMessages={messages}
                    initialPinned={pinned}
                    postUrl="/chat"
                    muted={muted}
                    mutedUntil={mutedUntil}
                    slowMode={slowMode}
                    moderation={
                        canModerate
                            ? {
                                  deleteUrl: (id) => `/chat/${id}`,
                                  muteUrl: (userId) =>
                                      `/chat/users/${userId}/mute`,
                                  pinUrl: (id) => `/chat/${id}/pin`,
                              }
                            : undefined
                    }
                />
            </ChatShell>
        </AppLayout>
    );
}
