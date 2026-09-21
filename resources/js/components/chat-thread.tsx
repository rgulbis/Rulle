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
    pinned: boolean;
    user: ChatUser;
};

export type SlowMode = {
    remaining_seconds: number;
    cooldown_seconds: number;
};

type Moderation = {
    deleteUrl: (messageId: number) => string;
    muteUrl: (userId: number) => string;
    pinUrl: (messageId: number) => string;
};

type Props = {
    channel: string;
    initialMessages: ChatMessage[];
    initialPinned?: ChatMessage[];
    postUrl: string;
    muted?: boolean;
    mutedUntil?: string | null;
    moderation?: Moderation;
    slowMode?: SlowMode;
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
    initialPinned = [],
    postUrl,
    muted = false,
    mutedUntil = null,
    moderation,
    slowMode,
}: Props) {
    const { auth } = usePage<{ auth: Auth }>().props;
    const [messages, setMessages] = useState(initialMessages);
    const [pinned, setPinned] = useState(initialPinned);
    const [body, setBody] = useState('');
    const [sending, setSending] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const bottomRef = useRef<HTMLDivElement>(null);

    // Slow mode is tracked as absolute client-side end times, derived from
    // the durations the server sends, so a skewed clock can't stretch it.
    const [now, setNow] = useState(() => Date.now());
    const [slow, setSlow] = useState(() => ({
        endsAt: Date.now() + (slowMode?.remaining_seconds ?? 0) * 1000,
        cooldownSeconds: slowMode?.cooldown_seconds ?? 0,
    }));
    const [cooldownEndsAt, setCooldownEndsAt] = useState(0);

    const isStaff = auth.user.role !== 'user';
    const slowActive = slow.endsAt > now;
    const cooldownRemaining =
        !isStaff && cooldownEndsAt > now
            ? Math.ceil((cooldownEndsAt - now) / 1000)
            : 0;
    const needsTicker = slowActive || cooldownEndsAt > now;

    useEffect(() => {
        if (!needsTicker) {
            return;
        }

        const id = setInterval(() => setNow(Date.now()), 1000);

        return () => clearInterval(id);
    }, [needsTicker]);

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
            setPinned((current) => current.filter((m) => m.id !== e.id));
        });

        echoChannel.listen(
            '.message.pin-changed',
            (e: { pinned: boolean; message: ChatMessage }) => {
                setMessages((current) =>
                    current.map((m) =>
                        m.id === e.message.id ? { ...m, pinned: e.pinned } : m,
                    ),
                );
                setPinned((current) => {
                    const others = current.filter((m) => m.id !== e.message.id);

                    return e.pinned ? [e.message, ...others] : others;
                });
            },
        );

        echoChannel.listen('.slow-mode.activated', (e: SlowMode) => {
            const current = Date.now();

            setSlow({
                endsAt: current + e.remaining_seconds * 1000,
                cooldownSeconds: e.cooldown_seconds,
            });
            setNow(current);
        });

        return () => {
            window.Echo.leave(channel);
        };
    }, [channel]);

    const sendMessage = () => {
        if (!body.trim() || cooldownRemaining > 0) {
            return;
        }

        setSending(true);

        router.post(
            postUrl,
            { body },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setBody('');
                    setError(null);

                    if (slowActive && !isStaff) {
                        const current = Date.now();

                        setCooldownEndsAt(
                            current + slow.cooldownSeconds * 1000,
                        );
                        setNow(current);
                    }
                },
                onError: (errors) =>
                    setError(errors.body ?? 'Could not send that message.'),
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

    const togglePin = (messageId: number, isPinned: boolean) => {
        if (!moderation) {
            return;
        }

        if (isPinned) {
            router.delete(moderation.pinUrl(messageId), {
                preserveScroll: true,
            });
        } else {
            router.post(
                moderation.pinUrl(messageId),
                {},
                { preserveScroll: true },
            );
        }
    };

    return (
        <div className="flex h-[60vh] flex-col rounded-none border border-gray-200 bg-white shadow-sm">
            {pinned.length > 0 && (
                <div className="max-h-32 overflow-y-auto border-b border-amber-200 bg-amber-50 px-4 py-2">
                    <p className="text-xs font-semibold tracking-wide text-amber-800 uppercase">
                        Pinned
                    </p>
                    <ul className="mt-1 flex flex-col gap-1">
                        {pinned.map((message) => (
                            <li
                                key={message.id}
                                className="flex items-start justify-between gap-2 text-sm text-gray-800"
                            >
                                <span className="min-w-0 break-words whitespace-pre-wrap">
                                    <span className="font-semibold">
                                        {message.user.name}:
                                    </span>{' '}
                                    {message.body}
                                </span>
                                {moderation && (
                                    <button
                                        type="button"
                                        onClick={() =>
                                            togglePin(message.id, true)
                                        }
                                        className="shrink-0 text-xs font-medium text-amber-700 hover:text-amber-600"
                                    >
                                        Unpin
                                    </button>
                                )}
                            </li>
                        ))}
                    </ul>
                </div>
            )}

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

                                            {moderation && (
                                                <span className="hidden items-center gap-2 text-xs group-hover:flex">
                                                    <button
                                                        type="button"
                                                        onClick={() =>
                                                            togglePin(
                                                                message.id,
                                                                message.pinned,
                                                            )
                                                        }
                                                        className="font-medium text-gray-600 hover:text-gray-900"
                                                    >
                                                        {message.pinned
                                                            ? 'Unpin'
                                                            : 'Pin'}
                                                    </button>
                                                    {!isOwn && (
                                                        <>
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
                                                        </>
                                                    )}
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

            <div className="border-t border-gray-200">
                {slowActive && (
                    <p className="border-b border-amber-200 bg-amber-50 px-3 py-1.5 text-xs text-amber-800">
                        {isStaff
                            ? "Slow mode is on — it doesn't apply to staff."
                            : `Slow mode is on — one message every ${slow.cooldownSeconds}s.`}
                    </p>
                )}

                <form onSubmit={submit} className="flex gap-2 p-3">
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
                                disabled={
                                    sending ||
                                    !body.trim() ||
                                    cooldownRemaining > 0
                                }
                                className="self-end rounded-none bg-yellow-400 px-4 py-2 text-sm font-semibold text-black hover:bg-yellow-300 disabled:opacity-50"
                            >
                                {cooldownRemaining > 0
                                    ? `Wait ${cooldownRemaining}s`
                                    : 'Send'}
                            </button>
                        </>
                    )}
                </form>

                {error && (
                    <p className="px-3 pb-2 text-sm text-red-600">{error}</p>
                )}
            </div>
        </div>
    );
}
