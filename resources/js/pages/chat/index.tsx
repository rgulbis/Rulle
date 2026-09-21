import { Head } from '@inertiajs/react';
import ChatThread, { ChatMessage, SlowMode } from '@/components/chat-thread';
import AppLayout from '@/layouts/app-layout';

type Props = {
    messages: ChatMessage[];
    pinned: ChatMessage[];
    slowMode: SlowMode;
    canModerate: boolean;
    muted: boolean;
    mutedUntil: string | null;
};

export default function ChatIndex({
    messages,
    pinned,
    slowMode,
    canModerate,
    muted,
    mutedUntil,
}: Props) {
    return (
        <AppLayout>
            <Head title="Chat" />
            <div className="p-6">
                <div className="mx-auto flex max-w-3xl flex-col gap-4">
                    <h1 className="text-xl font-semibold text-gray-900">
                        Chat
                    </h1>

                    <ChatThread
                        channel="chat"
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
                </div>
            </div>
        </AppLayout>
    );
}
