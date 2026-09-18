import { router, usePage } from '@inertiajs/react';
import {
    FormEventHandler,
    KeyboardEventHandler,
    useEffect,
    useRef,
    useState,
} from 'react';
import type { Auth } from '@/types/auth';

const MAX_MESSAGE_LENGTH = 500;

export type ChatUser = {
    id: number;
    name: string;
    role: string;
};

export type ChatMessage = {
    id: number;
    body: string;
    created_at: string;
    user: ChatUser;
};

type Moderation = {
    deleteUrl: (messageId: number) => string;
    muteUrl: (userId: number) => string;
};

type Props = {
    channel: string;
    initialMessages: ChatMessage[];
    postUrl: string;
    muted?: boolean;
    mutedUntil?: string | null;
    moderation?: Moderation;
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

export default function ChatThread({
    channel,
    initialMessages,
    postUrl,
    muted = false,
    mutedUntil = null,
    moderation,
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
        const echoChannel = window.Echo.private(channel);

        echoChannel.listen('.message.sent', (message: ChatMessage) => {
            setMessages((current) => [...current, message]);
        });

        echoChannel.listen('.message.deleted', (e: { id: number }) => {
            setMessages((current) => current.filter((m) => m.id !== e.id));
        });

        return () => {
            window.Echo.leave(channel);
        };
    }, [channel]);

    const sendMessage = () => {
        if (!body.trim()) {
            return;
        }

        setSending(true);

        router.post(
            postUrl,
            { body },
            {
                preserveScroll: true,
                onSuccess: () => setBody(''),
                onFinish: () => setSending(false),
            },
        );
    };

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        sendMessage();
    };

    const handleComposerKeyDown: KeyboardEventHandler<HTMLTextAreaElement> = (
        e,
    ) => {
        // Enter sends, Shift+Enter inserts a newline — the usual chat
        // convention, since this is a multi-line composer.
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();

            if (!sending) {
                sendMessage();
            }
        }
    };

    const deleteMessage = (messageId: number) => {
        if (moderation) {
            router.delete(moderation.deleteUrl(messageId), {
                preserveScroll: true,
            });
        }
    };

    const muteUser = (userId: number, hours: number) => {
        if (moderation) {
            router.post(
                moderation.muteUrl(userId),
                { hours },
                { preserveScroll: true },
            );
        }
    };

    return (
        <div className="flex h-[60vh] flex-col rounded-none border border-gray-200 bg-white shadow-sm">
            <div className="flex-1 overflow-x-hidden overflow-y-auto p-4">
                {messages.length === 0 ? (
                    <p className="text-sm text-gray-500">
                        No messages yet — say something.
                    </p>
                ) : (
                    <ul className="flex flex-col gap-3">
                        {messages.map((message) => {
                            const isOwn = message.user.id === auth.user.id;

                            return (
                                <li
                                    key={message.id}
                                    className={`group flex ${isOwn ? 'justify-end' : 'justify-start'}`}
                                >
                                    <div className="flex max-w-[75%] min-w-0 flex-col">
                                        <div className="flex items-baseline gap-2">
                                            <span className="text-sm font-semibold text-gray-900">
                                                {message.user.name}
                                                {message.user.role !==
                                                    'user' && (
                                                    <span className="ml-1.5 rounded-none bg-amber-100 px-1.5 py-0.5 text-xs font-medium text-amber-800">
                                                        {message.user.role}
                                                    </span>
                                                )}
                                                <span className="ml-2 text-xs font-normal text-gray-400">
                                                    {formatTime(
                                                        message.created_at,
                                                    )}
                                                </span>
                                            </span>

                                            {moderation && !isOwn && (
                                                <span className="hidden items-center gap-2 text-xs group-hover:flex">
                                                    {MUTE_OPTIONS.map(
                                                        (option) => (
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
                                                                {option.label}
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
                                            className={`mt-1 min-w-0 px-3 py-2 text-sm break-words whitespace-pre-wrap text-gray-700 ${isOwn ? 'bg-yellow-100' : 'bg-gray-100'}`}
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
                        <textarea
                            value={body}
                            onChange={(e) => setBody(e.target.value)}
                            onKeyDown={handleComposerKeyDown}
                            placeholder="Say something…"
                            rows={2}
                            maxLength={MAX_MESSAGE_LENGTH}
                            className="w-full flex-1 resize-none rounded-none border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 shadow-sm transition outline-none placeholder:text-gray-400 focus:border-yellow-500 focus:ring-4 focus:ring-yellow-500/20"
                        />
                        <button
                            type="submit"
                            disabled={sending || !body.trim()}
                            className="self-end rounded-none bg-yellow-400 px-4 py-2 text-sm font-semibold text-black hover:bg-yellow-300 disabled:opacity-50"
                        >
                            Send
                        </button>
                    </>
                )}
            </form>
        </div>
    );
}
