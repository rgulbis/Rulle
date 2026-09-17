import { Head, router, usePage } from '@inertiajs/react';
import { FormEventHandler, useEffect, useRef, useState } from 'react';
import { TextInput } from '@/components/form-controls';
import AppLayout from '@/layouts/app-layout';
import type { Auth } from '@/types/auth';

type ChatUser = {
    id: number;
    name: string;
    role: string;
};

type Message = {
    id: number;
    body: string;
    created_at: string;
    user: ChatUser;
};

type Props = {
    messages: Message[];
    canModerate: boolean;
    muted: boolean;
    mutedUntil: string | null;
};

const MUTE_OPTIONS = [
    { hours: 1, label: '1 hour' },
    { hours: 24, label: '1 day' },
    { hours: 168, label: '1 week' },
];

function formatTime(dateTime: string) {
    return new Date(dateTime).toLocaleTimeString([], {
        hour: '2-digit',
        minute: '2-digit',
    });
}

export default function ChatIndex({
    messages: initialMessages,
    canModerate,
    muted,
    mutedUntil,
}: Props) {
    const { auth } = usePage<{ auth: Auth }>().props;
    const [messages, setMessages] = useState(initialMessages);
    const [body, setBody] = useState('');
    const [sending, setSending] = useState(false);
    const bottomRef = useRef<HTMLDivElement>(null);

    useEffect(() => {
        bottomRef.current?.scrollIntoView({ block: 'end' });
    }, [messages]);

    useEffect(() => {
        const channel = window.Echo.private('chat');

        channel.listen('.message.sent', (message: Message) => {
            setMessages((current) => [...current, message]);
        });

        channel.listen('.message.deleted', (e: { id: number }) => {
            setMessages((current) => current.filter((m) => m.id !== e.id));
        });

        return () => {
            window.Echo.leave('chat');
        };
    }, []);

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        if (!body.trim()) {
            return;
        }

        setSending(true);

        router.post(
            '/chat',
            { body },
            {
                preserveScroll: true,
                onSuccess: () => setBody(''),
                onFinish: () => setSending(false),
            },
        );
    };

    const deleteMessage = (messageId: number) => {
        router.delete(`/chat/${messageId}`, { preserveScroll: true });
    };

    const muteUser = (userId: number, hours: number) => {
        router.post(
            `/chat/users/${userId}/mute`,
            { hours },
            { preserveScroll: true },
        );
    };

    return (
        <AppLayout>
            <Head title="Chat" />
            <div className="p-6">
                <div className="mx-auto flex max-w-3xl flex-col gap-4">
                    <h1 className="text-xl font-semibold text-gray-900">
                        Chat
                    </h1>

                    <div className="flex h-[60vh] flex-col rounded-none border border-gray-200 bg-white shadow-sm">
                        <div className="flex-1 overflow-y-auto p-4">
                            {messages.length === 0 ? (
                                <p className="text-sm text-gray-500">
                                    No messages yet — say something.
                                </p>
                            ) : (
                                <ul className="flex flex-col gap-3">
                                    {messages.map((message) => {
                                        const isOwn =
                                            message.user.id === auth.user.id;

                                        return (
                                            <li
                                                key={message.id}
                                                className={`group flex ${isOwn ? 'justify-end' : 'justify-start'}`}
                                            >
                                                <div
                                                    className={`flex max-w-[75%] flex-col ${isOwn ? 'items-end' : 'items-start'}`}
                                                >
                                                    <div className="flex items-baseline gap-2">
                                                        <span className="text-sm font-semibold text-gray-900">
                                                            {message.user.name}
                                                            {message.user
                                                                .role !==
                                                                'user' && (
                                                                <span className="ml-1.5 rounded-none bg-amber-100 px-1.5 py-0.5 text-xs font-medium text-amber-800">
                                                                    {
                                                                        message
                                                                            .user
                                                                            .role
                                                                    }
                                                                </span>
                                                            )}
                                                            <span className="ml-2 text-xs font-normal text-gray-400">
                                                                {formatTime(
                                                                    message.created_at,
                                                                )}
                                                            </span>
                                                        </span>

                                                        {canModerate &&
                                                            !isOwn && (
                                                                <span className="hidden items-center gap-2 text-xs group-hover:flex">
                                                                    {MUTE_OPTIONS.map(
                                                                        (
                                                                            option,
                                                                        ) => (
                                                                            <button
                                                                                key={
                                                                                    option.hours
                                                                                }
                                                                                type="button"
                                                                                onClick={() =>
                                                                                    muteUser(
                                                                                        message
                                                                                            .user
                                                                                            .id,
                                                                                        option.hours,
                                                                                    )
                                                                                }
                                                                                className="font-medium text-amber-700 hover:text-amber-600"
                                                                            >
                                                                                Mute{' '}
                                                                                {
                                                                                    option.label
                                                                                }
                                                                            </button>
                                                                        ),
                                                                    )}
                                                                    <button
                                                                        type="button"
                                                                        onClick={() =>
                                                                            deleteMessage(
                                                                                message.id,
                                                                            )
                                                                        }
                                                                        className="font-medium text-red-600 hover:text-red-500"
                                                                    >
                                                                        Delete
                                                                    </button>
                                                                </span>
                                                            )}
                                                    </div>
                                                    <p
                                                        className={`mt-1 px-3 py-2 text-sm break-words text-gray-700 ${isOwn ? 'bg-yellow-100' : 'bg-gray-100'}`}
                                                    >
                                                        {message.body}
                                                    </p>
                                                </div>
                                            </li>
                                        );
                                    })}
                                </ul>
                            )}
                            <div ref={bottomRef} />
                        </div>

                        <form
                            onSubmit={submit}
                            className="flex gap-2 border-t border-gray-200 p-3"
                        >
                            {muted ? (
                                <p className="flex-1 self-center text-sm text-red-600">
                                    You're muted from chat
                                    {mutedUntil &&
                                        ` until ${new Date(mutedUntil).toLocaleString()}`}
                                    .
                                </p>
                            ) : (
                                <>
                                    <TextInput
                                        value={body}
                                        onChange={(e) =>
                                            setBody(e.target.value)
                                        }
                                        placeholder="Say something…"
                                        className="flex-1"
                                        maxLength={1000}
                                    />
                                    <button
                                        type="submit"
                                        disabled={sending || !body.trim()}
                                        className="rounded-none bg-yellow-400 px-4 py-2 text-sm font-semibold text-black hover:bg-yellow-300 disabled:opacity-50"
                                    >
                                        Send
                                    </button>
                                </>
                            )}
                        </form>
                    </div>
                </div>
            </div>
        </AppLayout>
    );
}
