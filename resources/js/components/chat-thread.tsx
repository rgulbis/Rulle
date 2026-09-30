import { router, usePage } from '@inertiajs/react';
import {
    FormEventHandler,
    KeyboardEventHandler,
    useEffect,
    useLayoutEffect,
    useMemo,
    useRef,
    useState,
} from 'react';
import { ArrowRightIcon, CrossIcon } from '@/components/icons';
import { useTranslation } from '@/lib/i18n/context';
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
    /** Shown as "# title" in the header, the composer and the channel start. */
    title: string;
    description?: string;
    initialMessages: ChatMessage[];
    initialPinned?: ChatMessage[];
    postUrl: string;
    muted?: boolean;
    mutedUntil?: string | null;
    moderation?: Moderation;
    slowMode?: SlowMode;
};

const MUTE_OPTIONS = [
    { hours: 1, labelKey: 'chatThread.muteHour' },
    { hours: 24, labelKey: 'chatThread.muteDay' },
    { hours: 168, labelKey: 'chatThread.muteWeek' },
] as const;

export default function ChatThread({
    channel,
    title,
    description,
    initialMessages,
    initialPinned = [],
    postUrl,
    muted = false,
    mutedUntil = null,
    moderation,
    slowMode,
}: Props) {
    const { auth } = usePage<{ auth: Auth }>().props;
    const { t, intlLocale } = useTranslation();
    const [messages, setMessages] = useState(initialMessages);
    const [pinned, setPinned] = useState(initialPinned);
    const [body, setBody] = useState('');
    const [sending, setSending] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [pinnedOpen, setPinnedOpen] = useState(false);
    // New messages that arrived while scrolled up reading older ones.
    const [unseen, setUnseen] = useState(0);
    const scrollerRef = useRef<HTMLDivElement>(null);
    const composerRef = useRef<HTMLTextAreaElement>(null);
    const stickToBottom = useRef(true);
    const seenCount = useRef(initialMessages.length);

    const formatTime = (dateTime: string) =>
        new Date(dateTime).toLocaleTimeString(intlLocale, {
            hour: '2-digit',
            minute: '2-digit',
        });

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

    // Only the message list scrolls — never the page. It follows new
    // messages while you're at the bottom (or when you sent one yourself),
    // and otherwise leaves you where you are with a "new messages" button.
    useLayoutEffect(() => {
        const scroller = scrollerRef.current;

        if (!scroller) {
            return;
        }

        const added = messages.length - seenCount.current;
        seenCount.current = messages.length;
        const last = messages[messages.length - 1];
        const ownLatest = added > 0 && last?.user.id === auth.user.id;

        if (stickToBottom.current || ownLatest) {
            scroller.scrollTop = scroller.scrollHeight;
            setUnseen(0);
        } else if (added > 0) {
            setUnseen((n) => n + added);
        }
    }, [messages, auth.user.id]);

    const handleScroll = () => {
        const scroller = scrollerRef.current;

        if (!scroller) {
            return;
        }

        stickToBottom.current =
            scroller.scrollHeight - scroller.scrollTop - scroller.clientHeight <
            80;

        if (stickToBottom.current) {
            setUnseen(0);
        }
    };

    const jumpToLatest = () => {
        scrollerRef.current?.scrollTo({
            top: scrollerRef.current.scrollHeight,
            behavior: 'smooth',
        });
    };

    // The composer grows with what's typed, up to a few lines.
    const resizeComposer = () => {
        const el = composerRef.current;

        if (el) {
            el.style.height = 'auto';
            el.style.height = `${Math.min(el.scrollHeight, 160)}px`;
        }
    };

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
                    requestAnimationFrame(() => {
                        resizeComposer();
                        composerRef.current?.focus();
                    });

                    if (slowActive && !isStaff) {
                        const current = Date.now();

                        setCooldownEndsAt(
                            current + slow.cooldownSeconds * 1000,
                        );
                        setNow(current);
                    }
                },
                onError: (errors) => {
                    // Slow mode: count the server's wait down live (the
                    // send button and the note below both follow it) rather
                    // than showing a fixed number that goes out of date.
                    const wait = Number(errors.slow_mode_wait);

                    if (wait > 0) {
                        const current = Date.now();

                        setCooldownEndsAt(current + wait * 1000);
                        setNow(current);
                        setError(null);

                        return;
                    }

                    setError(errors.body ?? t('chatThread.sendFailed'));
                },
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

    // Discord-style grouping: consecutive messages from the same person
    // within a few minutes share one name/avatar header, and a divider marks
    // each new day.
    const rows = useMemo(() => {
        const GROUP_GAP_MS = 5 * 60 * 1000;

        return messages.map((message, i) => {
            const previous = messages[i - 1];
            const at = new Date(message.created_at);
            const newDay =
                !previous ||
                new Date(previous.created_at).toDateString() !==
                    at.toDateString();
            const startsGroup =
                newDay ||
                previous.user.id !== message.user.id ||
                at.getTime() - new Date(previous.created_at).getTime() >
                    GROUP_GAP_MS;

            return { message, at, newDay, startsGroup };
        });
    }, [messages]);

    const dayLabel = (date: Date) => {
        const today = new Date();
        const yesterday = new Date();
        yesterday.setDate(today.getDate() - 1);

        if (date.toDateString() === today.toDateString()) {
            return t('chatThread.today');
        }

        if (date.toDateString() === yesterday.toDateString()) {
            return t('chatThread.yesterday');
        }

        return date.toLocaleDateString(intlLocale, {
            weekday: 'long',
            day: 'numeric',
            month: 'long',
        });
    };

    return (
        <section className="relative flex min-h-0 min-w-0 flex-1 flex-col">
            <header className="border-ink flex min-h-14 items-center gap-3 border-b-2 px-4 py-2 lg:px-6">
                <h1 className="flex min-w-0 items-center gap-2 text-lg font-semibold">
                    <span aria-hidden="true" className="text-faint text-2xl">
                        #
                    </span>
                    <span className="truncate">{title}</span>
                </h1>
                {description && (
                    <p className="text-muted border-line hidden min-w-0 truncate border-l-2 pl-3 text-sm md:block">
                        {description}
                    </p>
                )}
                {pinned.length > 0 && (
                    <button
                        type="button"
                        onClick={() => setPinnedOpen((open) => !open)}
                        aria-expanded={pinnedOpen}
                        aria-controls="pinned-messages"
                        className={`ml-auto flex min-h-11 shrink-0 items-center gap-2 border-2 px-3 text-sm font-semibold transition ${
                            pinnedOpen
                                ? 'border-ink bg-accent text-accent-ink'
                                : 'border-line hover:border-ink'
                        }`}
                    >
                        <PinIcon />
                        {t('chatThread.pinnedCount', { count: pinned.length })}
                    </button>
                )}
            </header>

            {pinnedOpen && pinned.length > 0 && (
                <div
                    id="pinned-messages"
                    className="border-ink bg-paper shadow-hard-lg absolute top-16 right-4 z-20 flex max-h-96 w-[min(28rem,calc(100%-2rem))] flex-col border-2"
                >
                    <div className="border-ink flex items-center justify-between border-b-2 px-4 py-1">
                        <p className="font-mono text-xs font-semibold tracking-[0.12em] uppercase">
                            {t('chatThread.pinned')}
                        </p>
                        <button
                            type="button"
                            onClick={() => setPinnedOpen(false)}
                            aria-label={t('chatThread.closePinned')}
                            className="flex size-11 items-center justify-center"
                        >
                            <CrossIcon size={18} />
                        </button>
                    </div>
                    <ul className="divide-line flex flex-col divide-y overflow-y-auto">
                        {pinned.map((message) => (
                            <li
                                key={message.id}
                                className="flex gap-3 px-4 py-3"
                            >
                                <Avatar user={message.user} size="sm" />
                                <div className="min-w-0 flex-1">
                                    <p className="text-sm font-semibold">
                                        {message.user.name}
                                    </p>
                                    <p className="text-base break-words whitespace-pre-wrap">
                                        {message.body}
                                    </p>
                                </div>
                                {moderation && (
                                    <button
                                        type="button"
                                        onClick={() =>
                                            togglePin(message.id, true)
                                        }
                                        className="shrink-0 self-start text-sm font-semibold underline underline-offset-4"
                                    >
                                        {t('chatThread.unpin')}
                                    </button>
                                )}
                            </li>
                        ))}
                    </ul>
                </div>
            )}

            <div
                ref={scrollerRef}
                onScroll={handleScroll}
                className="min-h-0 flex-1 overflow-x-hidden overflow-y-auto"
            >
                <div className="flex min-h-full flex-col justify-end pb-4">
                    <div className="px-4 pt-10 pb-6 lg:px-6">
                        <p
                            aria-hidden="true"
                            className="bg-ink text-ground font-display flex size-16 items-center justify-center text-4xl font-black"
                        >
                            #
                        </p>
                        <p className="font-display mt-4 text-4xl font-black uppercase">
                            {title}
                        </p>
                        <p className="text-muted mt-1 text-base">
                            {messages.length === 0
                                ? t('chatThread.noMessages')
                                : t('chatThread.channelStart', {
                                      channel: title,
                                  })}
                        </p>
                    </div>

                    <ol aria-label={title} className="flex flex-col">
                        {rows.map(({ message, at, newDay, startsGroup }) => {
                            const isOwn = message.user.id === auth.user.id;
                            // Mirrors App\Support\ChatModeration: the inline
                            // moderators are employees, who may only delete
                            // and mute customers — never staff or admins.
                            const canPenalise =
                                moderation !== undefined &&
                                !isOwn &&
                                message.user.role === 'user';

                            return (
                                <li key={message.id}>
                                    {newDay && (
                                        <div
                                            role="separator"
                                            className="text-muted my-3 flex items-center gap-3 px-4 font-mono text-xs font-semibold tracking-[0.08em] uppercase lg:px-6"
                                        >
                                            <span className="bg-line h-0.5 flex-1" />
                                            {dayLabel(at)}
                                            <span className="bg-line h-0.5 flex-1" />
                                        </div>
                                    )}
                                    <div
                                        className={`group hover:bg-paper focus-within:bg-paper relative flex gap-4 border-l-4 px-4 lg:px-6 ${
                                            startsGroup
                                                ? 'mt-3 pt-1 pb-0.5'
                                                : 'py-0.5'
                                        } ${message.pinned ? 'border-accent' : 'border-transparent'}`}
                                    >
                                        <div className="w-10 shrink-0">
                                            {startsGroup ? (
                                                <Avatar user={message.user} />
                                            ) : (
                                                <time
                                                    dateTime={
                                                        message.created_at
                                                    }
                                                    className="text-faint hidden pt-1 text-right font-mono text-[11px] group-hover:block"
                                                >
                                                    {formatTime(
                                                        message.created_at,
                                                    )}
                                                </time>
                                            )}
                                        </div>
                                        <div className="min-w-0 flex-1">
                                            {startsGroup && (
                                                <p className="flex flex-wrap items-baseline gap-x-2">
                                                    <span className="font-semibold">
                                                        {message.user.name}
                                                    </span>
                                                    {isOwn && (
                                                        <span className="text-muted text-sm">
                                                            (
                                                            {t(
                                                                'chatThread.you',
                                                            )}
                                                            )
                                                        </span>
                                                    )}
                                                    {message.user.role !==
                                                        'user' && (
                                                        <span className="bg-ink text-ground px-1.5 py-0.5 font-mono text-[11px] font-semibold tracking-[0.08em] uppercase">
                                                            {message.user
                                                                .role ===
                                                            'admin'
                                                                ? t(
                                                                      'chatThread.roleAdmin',
                                                                  )
                                                                : t(
                                                                      'chatThread.roleStaff',
                                                                  )}
                                                        </span>
                                                    )}
                                                    <time
                                                        dateTime={
                                                            message.created_at
                                                        }
                                                        className="text-muted font-mono text-xs"
                                                    >
                                                        {formatTime(
                                                            message.created_at,
                                                        )}
                                                    </time>
                                                </p>
                                            )}
                                            <p className="text-base break-words whitespace-pre-wrap">
                                                {message.body}
                                            </p>
                                        </div>

                                        {moderation && (
                                            <div className="border-ink bg-paper shadow-hard absolute -top-4 right-4 z-10 hidden items-stretch border-2 text-xs font-semibold group-focus-within:flex group-hover:flex">
                                                <button
                                                    type="button"
                                                    onClick={() =>
                                                        togglePin(
                                                            message.id,
                                                            message.pinned,
                                                        )
                                                    }
                                                    className="hover:bg-accent hover:text-accent-ink flex min-h-9 items-center gap-1.5 px-2.5"
                                                >
                                                    <PinIcon size={14} />
                                                    {message.pinned
                                                        ? t('chatThread.unpin')
                                                        : t('chatThread.pin')}
                                                </button>
                                                {canPenalise && (
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
                                                                    className="border-line hover:bg-accent hover:text-accent-ink border-l-2 px-2.5"
                                                                >
                                                                    {t(
                                                                        'chatThread.mute',
                                                                        {
                                                                            duration:
                                                                                t(
                                                                                    option.labelKey,
                                                                                ),
                                                                        },
                                                                    )}
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
                                                            className="border-line text-danger hover:bg-danger-fill border-l-2 px-2.5 hover:text-white"
                                                        >
                                                            {t(
                                                                'chatThread.delete',
                                                            )}
                                                        </button>
                                                    </>
                                                )}
                                            </div>
                                        )}
                                    </div>
                                </li>
                            );
                        })}
                    </ol>
                </div>
            </div>

            {unseen > 0 && (
                <button
                    type="button"
                    onClick={jumpToLatest}
                    className="border-ink bg-accent text-accent-ink shadow-hard absolute bottom-28 left-1/2 z-10 -translate-x-1/2 border-2 px-4 py-2 text-sm font-semibold"
                >
                    {t('chatThread.jumpToNew', { count: unseen })}
                </button>
            )}

            <div className="px-4 pt-1 pb-4 lg:px-6">
                {slowActive && (
                    <p className="border-ink bg-accent text-accent-ink border-2 border-b-0 px-4 py-1.5 text-sm font-medium">
                        {isStaff
                            ? t('chatThread.slowModeStaff')
                            : t('chatThread.slowModeCustomer', {
                                  seconds: slow.cooldownSeconds,
                              })}
                    </p>
                )}

                {muted ? (
                    <p className="border-danger text-danger border-2 px-4 py-3 text-base font-medium">
                        {mutedUntil
                            ? t('chatThread.mutedUntil', {
                                  date: new Date(mutedUntil).toLocaleString(
                                      intlLocale,
                                  ),
                              })
                            : t('chatThread.muted')}
                    </p>
                ) : (
                    <form
                        onSubmit={submit}
                        className="border-ink bg-paper focus-within:shadow-hard-accent flex items-end gap-2 border-2 p-1.5 transition"
                    >
                        <textarea
                            ref={composerRef}
                            value={body}
                            onChange={(e) => {
                                setBody(e.target.value);
                                resizeComposer();
                            }}
                            onKeyDown={handleComposerKeyDown}
                            placeholder={t('chatThread.messageTo', {
                                channel: title,
                            })}
                            aria-label={t('chatThread.messageTo', {
                                channel: title,
                            })}
                            rows={1}
                            maxLength={MAX_MESSAGE_LENGTH}
                            className="placeholder:text-faint max-h-40 min-h-11 flex-1 resize-none bg-transparent px-3 py-2.5 text-base outline-none"
                        />
                        <button
                            type="submit"
                            disabled={
                                sending || !body.trim() || cooldownRemaining > 0
                            }
                            aria-label={t('chatThread.send')}
                            className="bg-ink text-ground flex min-h-11 min-w-11 shrink-0 items-center justify-center gap-2 px-3 font-semibold transition disabled:opacity-40"
                        >
                            {cooldownRemaining > 0 ? (
                                t('chatThread.wait', {
                                    seconds: cooldownRemaining,
                                })
                            ) : (
                                <ArrowRightIcon />
                            )}
                        </button>
                    </form>
                )}

                {cooldownRemaining > 0 ? (
                    <p
                        aria-live="polite"
                        className="text-danger mt-1.5 text-sm font-medium"
                    >
                        {t('chatThread.slowModeWait', {
                            seconds: cooldownRemaining,
                        })}
                    </p>
                ) : error ? (
                    <p
                        role="alert"
                        className="text-danger mt-1.5 text-sm font-medium"
                    >
                        {error}
                    </p>
                ) : (
                    <p className="text-faint mt-1.5 hidden font-mono text-[11px] sm:block">
                        {t('chatThread.sendHint')}
                    </p>
                )}
            </div>
        </section>
    );
}

// Boxy initials instead of photos — every rider gets a steady colour picked
// from their id; staff always get the black-and-yellow one.
const AVATAR_COLOURS = [
    'bg-[#ffd400] text-[#16161a]',
    'bg-[#2f6fed] text-white',
    'bg-[#1f8a4c] text-white',
    'bg-[#d7261e] text-white',
    'bg-[#7c4dcc] text-white',
    'bg-[#e0701f] text-[#16161a]',
];

export function Avatar({
    user,
    size = 'md',
}: {
    user: ChatUser;
    size?: 'sm' | 'md';
}) {
    const initials = user.name
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part[0]?.toUpperCase())
        .join('');
    const colour =
        user.role === 'user'
            ? AVATAR_COLOURS[user.id % AVATAR_COLOURS.length]
            : 'bg-[#16161a] text-[#ffd400] ring-2 ring-inset ring-[#ffd400]';

    return (
        <span
            aria-hidden="true"
            className={`font-display flex shrink-0 items-center justify-center font-black ${colour} ${
                size === 'sm' ? 'size-8 text-base' : 'size-10 text-xl'
            }`}
        >
            {initials || '?'}
        </span>
    );
}

function PinIcon({ size = 16 }: { size?: number }) {
    return (
        <svg
            width={size}
            height={size}
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            strokeWidth="2.2"
            strokeLinecap="square"
            aria-hidden="true"
        >
            <path d="M9 3h6l-1 6 4 4H6l4-4zM12 13v8" />
        </svg>
    );
}
