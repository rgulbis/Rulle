import { Head } from '@inertiajs/react';
import ChatThread, { ChatMessage } from '@/components/chat-thread';
import AppLayout from '@/layouts/app-layout';

type Props = {
    messages: ChatMessage[];
    canModerate: boolean;
    muted: boolean;
    mutedUntil: string | null;
};

export default function ChatIndex({
    messages,
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
                        postUrl="/chat"
                        muted={muted}
                        mutedUntil={mutedUntil}
                        moderation={
                            canModerate
                                ? {
                                      deleteUrl: (id) => `/chat/${id}`,
                                      muteUrl: (userId) =>
                                          `/chat/users/${userId}/mute`,
                                  }
                                : undefined
                        }
                    />
                </div>
            </div>
        </AppLayout>
    );
}
