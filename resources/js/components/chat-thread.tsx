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
import { Button, Label, Select, TextInput } from '@/components/form-controls';
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
    reply_to: { id: number; body: string; user: { name: string } } | null;
};

export type SlowMode = {
    remaining_seconds: number;
    cooldown_seconds: number;
};

export type MutedUser = {
    id: number;
    name: string;
    chat_muted_until: string;
};

type Moderation = {
    deleteUrl: (messageId: number) => string;
    muteUrl: (userId: number) => string;
    unmuteUrl: (userId: number) => string;
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
    /** Who this room's moderator can currently unmute — fed from the
     * server rather than derived from messages, since a muted account
     * with nothing in the visible message window would otherwise have no
     * way to be found and unmuted at all. */
    mutedUsers?: MutedUser[];
    slowMode?: SlowMode;
};

const CUSTOM_MUTE_UNITS = [
    { hoursPer: 1, labelKey: 'chatThread.muteUnitHours' },
    { hoursPer: 24, labelKey: 'chatThread.muteUnitDays' },
    { hoursPer: 168, labelKey: 'chatThread.muteUnitWeeks' },
] as const;

// Matches the server-side max:8760 on the mute endpoints (one year).
const MAX_MUTE_HOURS = 8760;

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
    mutedUsers = [],
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
    const [mutedUsersOpen, setMutedUsersOpen] = useState(false);
    const [replyingTo, setReplyingTo] = useState<ChatMessage | null>(null);
    const [customMute, setCustomMute] = useState<{
        id: number;
        name: string;
    } | null>(null);
    const [customAmount, setCustomAmount] = useState('1');
    const [customUnit, setCustomUnit] = useState<number>(1);
    // The pin/mute/delete bar normally only reveals on hover/focus — there's
    // no hover on a touchscreen, so staff on a phone had no way to ever see
    // it. Below the `lg` breakpoint a per-message button toggles it instead.
    const [openActionsFor, setOpenActionsFor] = useState<number | null>(null);
    // Confirms a moderation action (right now: muting) actually went
    // through — muteUser() used to fire-and-forget with no feedback at all.
    const [moderationNotice, setModerationNotice] = useState<string | null>(
        null,
    );
    // New messages that arrived while scrolled up reading older ones.
    const [unseen, setUnseen] = useState(0);
    // Briefly flashed on whichever message was just jumped to from the
    // pinned list, so landing on it doesn't feel like nothing happened.
    const [justJumpedTo, setJustJumpedTo] = useState<number | null>(null);
    const scrollerRef = useRef<HTMLDivElement>(null);
    const messageRefs = useRef<Record<number, HTMLLIElement | null>>({});
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
        if (!moderationNotice) {
            return;
        }

        const id = setTimeout(() => setModerationNotice(null), 4000);

        return () => clearTimeout(id);
    }, [moderationNotice]);

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

    // A pinned message can be much older than the ~100 recent ones this
    // page loaded, in which case it simply isn't in the DOM to scroll to —
    // silently doing nothing is the right fallback there rather than an
    // error, since there's nowhere sensible to jump.
    const jumpToMessage = (messageId: number) => {
        setPinnedOpen(false);
        messageRefs.current[messageId]?.scrollIntoView({
            behavior: 'smooth',
            block: 'center',
        });
        setJustJumpedTo(messageId);
    };

    useEffect(() => {
        if (justJumpedTo === null) {
            return;
        }

        const id = setTimeout(() => setJustJumpedTo(null), 1500);

        return () => clearTimeout(id);
    }, [justJumpedTo]);

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
            { body, reply_to_message_id: replyingTo?.id ?? null },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setBody('');
                    setError(null);
                    setReplyingTo(null);
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

    const muteUser = (
        userId: number,
        userName: string,
        hours: number,
        durationLabel: string,
    ) => {
        if (!moderation) {
            return;
        }

        router.post(
            moderation.muteUrl(userId),
            { hours },
            {
                preserveScroll: true,
                onSuccess: () =>
                    setModerationNotice(
                        t('chatThread.muteSuccess', {
                            name: userName,
                            duration: durationLabel,
                        }),
                    ),
                // Reachable in practice only via a stale UI (the buttons
                // that lead here are already hidden for a disallowed
                // target) — a generic message is enough for that edge case.
                onError: () => setModerationNotice(t('chatThread.muteFailed')),
            },
        );
    };

    const customHours = Math.floor(Number(customAmount)) * customUnit;
    const customMuteValid =
        Number.isFinite(customHours) &&
        customHours >= 1 &&
        customHours <= MAX_MUTE_HOURS;

    const submitCustomMute: FormEventHandler = (event) => {
        event.preventDefault();

        if (!customMute || !customMuteValid) {
            return;
        }

        const unit = CUSTOM_MUTE_UNITS.find((u) => u.hoursPer === customUnit);

        muteUser(
            customMute.id,
            customMute.name,
            customHours,
            `${Math.floor(Number(customAmount))} ${t(unit?.labelKey ?? 'chatThread.muteUnitHours').toLowerCase()}`,
        );
        setCustomMute(null);
    };

    const unmuteUser = (userId: number, userName: string) => {
        if (!moderation) {
            return;
        }

        router.post(
            moderation.unmuteUrl(userId),
            {},
            {
                preserveScroll: true,
                onSuccess: () =>
                    setModerationNotice(
                        t('chatThread.unmuteSuccess', { name: userName }),
                    ),
                onError: () =>
                    setModerationNotice(t('chatThread.unmuteFailed')),
            },
        );
    };

    const startReply = (message: ChatMessage) => {
        setReplyingTo(message);
        composerRef.current?.focus();
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
                {mutedUsers.length > 0 && (
                    <button
                        type="button"
                        onClick={() => setMutedUsersOpen((open) => !open)}
                        aria-expanded={mutedUsersOpen}
                        aria-controls="muted-users"
                        className={`flex min-h-11 shrink-0 items-center gap-2 border-2 px-3 text-sm font-semibold transition ${
                            pinned.length === 0 ? 'ml-auto' : ''
                        } ${
                            mutedUsersOpen
                                ? 'border-ink bg-accent text-accent-ink'
                                : 'border-line hover:border-ink'
                        }`}
                    >
                        <MutedIcon />
                        {t('chatThread.mutedUsersCount', {
                            count: mutedUsers.length,
                        })}
                    </button>
                )}
            </header>

            {moderationNotice && (
                <p
                    role="status"
                    className="bg-accent text-accent-ink border-ink border-b-2 px-4 py-1.5 text-center text-sm font-medium"
                >
                    {moderationNotice}
                </p>
            )}

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
                                <button
                                    type="button"
                                    onClick={() => jumpToMessage(message.id)}
                                    className="hover:bg-line/40 min-w-0 flex-1 rounded-none text-left"
                                >
                                    <p className="text-sm font-semibold">
                                        {message.user.name}
                                    </p>
                                    <p className="text-base break-words whitespace-pre-wrap">
                                        {message.body}
                                    </p>
                                </button>
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

            {customMute && (
                <div
                    className="bg-ink/50 fixed inset-0 z-50 flex items-center justify-center p-4"
                    onClick={() => setCustomMute(null)}
                >
                    <form
                        role="dialog"
                        aria-modal="true"
                        aria-labelledby="custom-mute-title"
                        onSubmit={submitCustomMute}
                        onClick={(event) => event.stopPropagation()}
                        onKeyDown={(event) => {
                            if (event.key === 'Escape') {
                                setCustomMute(null);
                            }
                        }}
                        className="border-ink bg-paper shadow-hard-lg flex w-full max-w-sm flex-col gap-4 border-2 p-5"
                    >
                        <p
                            id="custom-mute-title"
                            className="font-mono text-sm font-semibold tracking-[0.12em] uppercase"
                        >
                            {t('chatThread.muteCustomTitle', {
                                name: customMute.name,
                            })}
                        </p>
                        <div className="flex gap-3">
                            <div className="flex flex-1 flex-col gap-1.5">
                                <Label htmlFor="custom-mute-amount">
                                    {t('chatThread.muteAmount')}
                                </Label>
                                <TextInput
                                    id="custom-mute-amount"
                                    type="number"
                                    min={1}
                                    step={1}
                                    autoFocus
                                    value={customAmount}
                                    onChange={(e) =>
                                        setCustomAmount(e.target.value)
                                    }
                                />
                            </div>
                            <div className="flex flex-1 flex-col gap-1.5">
                                <Label htmlFor="custom-mute-unit">
                                    {t('chatThread.muteUnit')}
                                </Label>
                                <Select
                                    id="custom-mute-unit"
                                    value={customUnit}
                                    onChange={(e) =>
                                        setCustomUnit(Number(e.target.value))
                                    }
                                >
                                    {CUSTOM_MUTE_UNITS.map((u) => (
                                        <option
                                            key={u.hoursPer}
                                            value={u.hoursPer}
                                        >
                                            {t(u.labelKey)}
                                        </option>
                                    ))}
                                </Select>
                            </div>
                        </div>
                        {!customMuteValid && (
                            <p className="text-danger text-sm">
                                {t('chatThread.muteCustomInvalid')}
                            </p>
                        )}
                        <div className="flex justify-end gap-3">
                            <Button
                                variant="ghost"
                                onClick={() => setCustomMute(null)}
                            >
                                {t('chatThread.muteCancel')}
                            </Button>
                            <Button type="submit" disabled={!customMuteValid}>
                                {t('chatThread.muteConfirm')}
                            </Button>
                        </div>
                    </form>
                </div>
            )}

            {mutedUsersOpen && mutedUsers.length > 0 && (
                <div
                    id="muted-users"
                    className="border-ink bg-paper shadow-hard-lg absolute top-16 right-4 z-20 flex max-h-96 w-[min(28rem,calc(100%-2rem))] flex-col border-2"
                >
                    <div className="border-ink flex items-center justify-between border-b-2 px-4 py-1">
                        <p className="font-mono text-xs font-semibold tracking-[0.12em] uppercase">
                            {t('chatThread.mutedUsers')}
                        </p>
                        <button
                            type="button"
                            onClick={() => setMutedUsersOpen(false)}
                            aria-label={t('chatThread.closeMutedUsers')}
                            className="flex size-11 items-center justify-center"
                        >
                            <CrossIcon size={18} />
                        </button>
                    </div>
                    <ul className="divide-line flex flex-col divide-y overflow-y-auto">
                        {mutedUsers.map((mutedUser) => (
                            <li
                                key={mutedUser.id}
                                className="flex items-center gap-3 px-4 py-3"
                            >
                                <div className="min-w-0 flex-1">
                                    <p className="text-sm font-semibold">
                                        {mutedUser.name}
                                    </p>
                                    <p className="text-muted text-sm">
                                        {t('chatThread.mutedUserUntil', {
                                            date: new Date(
                                                mutedUser.chat_muted_until,
                                            ).toLocaleString(intlLocale),
                                        })}
                                    </p>
                                </div>
                                <button
                                    type="button"
                                    onClick={() => {
                                        unmuteUser(
                                            mutedUser.id,
                                            mutedUser.name,
                                        );
                                        setMutedUsersOpen(false);
                                    }}
                                    className="shrink-0 self-start text-sm font-semibold underline underline-offset-4"
                                >
                                    {t('chatThread.unmute')}
                                </button>
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
                                <li
                                    key={message.id}
                                    ref={(el) => {
                                        messageRefs.current[message.id] = el;
                                    }}
                                >
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
                                        className={`group hover:bg-paper focus-within:bg-paper relative flex gap-4 border-l-4 px-4 transition-colors lg:px-6 ${
                                            startsGroup
                                                ? 'mt-3 pt-1 pb-0.5'
                                                : 'py-0.5'
                                        } ${openActionsFor === message.id ? 'z-30' : ''} ${message.pinned ? 'border-accent' : 'border-transparent'} ${
                                            justJumpedTo === message.id
                                                ? 'bg-accent/20'
                                                : ''
                                        }`}
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
                                            {message.reply_to && (
                                                <button
                                                    type="button"
                                                    onClick={() =>
                                                        jumpToMessage(
                                                            message.reply_to!
                                                                .id,
                                                        )
                                                    }
                                                    className="border-line hover:border-ink mb-1 flex max-w-full items-baseline gap-1.5 border-l-2 py-0.5 pl-2 text-left text-sm"
                                                >
                                                    <span className="text-muted shrink-0 font-semibold">
                                                        {
                                                            message.reply_to
                                                                .user.name
                                                        }
                                                    </span>
                                                    <span className="text-muted truncate">
                                                        {message.reply_to.body}
                                                    </span>
                                                </button>
                                            )}
                                            <p className="text-base break-words whitespace-pre-wrap">
                                                {message.body}
                                            </p>
                                        </div>

                                        {/* Reply is for everyone, so this bar
                                        always exists now — pin/mute/delete
                                        are the only parts still gated by
                                        moderation rights. */}
                                        <button
                                            type="button"
                                            onClick={() =>
                                                setOpenActionsFor((current) =>
                                                    current === message.id
                                                        ? null
                                                        : message.id,
                                                )
                                            }
                                            aria-label={t(
                                                'chatThread.moreActions',
                                            )}
                                            aria-expanded={
                                                openActionsFor === message.id
                                            }
                                            className={`absolute top-1 right-1 flex size-8 items-center justify-center lg:hidden ${
                                                openActionsFor === message.id
                                                    ? 'border-ink bg-paper z-40 border-2'
                                                    : 'text-faint z-20'
                                            }`}
                                        >
                                            {openActionsFor === message.id ? (
                                                <CrossIcon size={14} />
                                            ) : (
                                                <MoreIcon />
                                            )}
                                        </button>
                                        <div
                                            className={`border-ink bg-paper shadow-hard bg-line absolute top-10 right-1 z-30 max-w-[calc(100%-0.5rem)] flex-wrap items-stretch gap-0.5 border-2 text-xs font-semibold lg:-top-4 lg:right-4 lg:group-focus-within:flex lg:group-hover:flex ${
                                                openActionsFor === message.id
                                                    ? 'flex'
                                                    : 'hidden'
                                            }`}
                                        >
                                            <button
                                                type="button"
                                                onClick={() => {
                                                    startReply(message);
                                                    setOpenActionsFor(null);
                                                }}
                                                className="bg-paper hover:bg-accent hover:text-accent-ink flex min-h-9 flex-1 items-center justify-center gap-1.5 px-2.5"
                                            >
                                                <ReplyIcon size={14} />
                                                {t('chatThread.reply')}
                                            </button>
                                            {moderation && (
                                                <button
                                                    type="button"
                                                    onClick={() => {
                                                        togglePin(
                                                            message.id,
                                                            message.pinned,
                                                        );
                                                        setOpenActionsFor(null);
                                                    }}
                                                    className="bg-paper hover:bg-accent hover:text-accent-ink flex min-h-9 flex-1 items-center justify-center gap-1.5 px-2.5"
                                                >
                                                    <PinIcon size={14} />
                                                    {message.pinned
                                                        ? t('chatThread.unpin')
                                                        : t('chatThread.pin')}
                                                </button>
                                            )}
                                            {canPenalise && (
                                                <>
                                                    <button
                                                        type="button"
                                                        onClick={() => {
                                                            setCustomMute({
                                                                id: message.user
                                                                    .id,
                                                                name: message
                                                                    .user.name,
                                                            });
                                                            setCustomAmount(
                                                                '1',
                                                            );
                                                            setCustomUnit(1);
                                                            setOpenActionsFor(
                                                                null,
                                                            );
                                                        }}
                                                        className="bg-paper hover:bg-accent hover:text-accent-ink min-h-9 flex-1 px-2.5"
                                                    >
                                                        {t(
                                                            'chatThread.muteCustom',
                                                        )}
                                                    </button>
                                                    {mutedUsers.some(
                                                        (u) =>
                                                            u.id ===
                                                            message.user.id,
                                                    ) && (
                                                        <button
                                                            type="button"
                                                            onClick={() => {
                                                                unmuteUser(
                                                                    message.user
                                                                        .id,
                                                                    message.user
                                                                        .name,
                                                                );
                                                                setOpenActionsFor(
                                                                    null,
                                                                );
                                                            }}
                                                            className="bg-paper hover:bg-accent hover:text-accent-ink min-h-9 flex-1 px-2.5"
                                                        >
                                                            {t(
                                                                'chatThread.unmute',
                                                            )}
                                                        </button>
                                                    )}
                                                    <button
                                                        type="button"
                                                        onClick={() => {
                                                            deleteMessage(
                                                                message.id,
                                                            );
                                                            setOpenActionsFor(
                                                                null,
                                                            );
                                                        }}
                                                        className="bg-paper text-danger hover:bg-danger-fill min-h-9 flex-1 px-2.5 hover:text-white"
                                                    >
                                                        {t('chatThread.delete')}
                                                    </button>
                                                </>
                                            )}
                                        </div>
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
                    <>
                        {replyingTo && (
                            <div className="border-ink bg-paper flex items-center gap-2 border-2 border-b-0 px-3 py-2">
                                <div className="min-w-0 flex-1">
                                    <p className="text-muted font-mono text-[11px] font-semibold tracking-[0.08em] uppercase">
                                        {t('chatThread.replyingTo', {
                                            name: replyingTo.user.name,
                                        })}
                                    </p>
                                    <p className="truncate text-sm">
                                        {replyingTo.body}
                                    </p>
                                </div>
                                <button
                                    type="button"
                                    onClick={() => setReplyingTo(null)}
                                    aria-label={t('chatThread.cancelReply')}
                                    className="flex size-8 shrink-0 items-center justify-center"
                                >
                                    <CrossIcon size={16} />
                                </button>
                            </div>
                        )}
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
                                    sending ||
                                    !body.trim() ||
                                    cooldownRemaining > 0
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
                    </>
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

function MutedIcon({ size = 16 }: { size?: number }) {
    return (
        <svg
            width={size}
            height={size}
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            strokeWidth="2.2"
            strokeLinecap="square"
            strokeLinejoin="round"
            aria-hidden="true"
        >
            <path d="M15 9V5a3 3 0 0 0-5.6-1.5M9 9v3a3 3 0 0 0 4.8 2.4M12 18v3M8 21h8M3 3l18 18" />
        </svg>
    );
}

function ReplyIcon({ size = 16 }: { size?: number }) {
    return (
        <svg
            width={size}
            height={size}
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            strokeWidth="2.2"
            strokeLinecap="square"
            strokeLinejoin="round"
            aria-hidden="true"
        >
            <path d="M9 10L4 15l5 5M4 15h10a6 6 0 0 0 6-6V7" />
        </svg>
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

function MoreIcon({ size = 16 }: { size?: number }) {
    return (
        <svg
            width={size}
            height={size}
            viewBox="0 0 24 24"
            fill="currentColor"
            aria-hidden="true"
        >
            <circle cx="5" cy="12" r="2.2" />
            <circle cx="12" cy="12" r="2.2" />
            <circle cx="19" cy="12" r="2.2" />
        </svg>
    );
}
