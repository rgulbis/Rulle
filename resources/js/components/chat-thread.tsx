import { router, usePage } from '@inertiajs/react';
import {
    FormEventHandler,
    KeyboardEventHandler,
    useEffect,
    useMemo,
    useRef,
    useState,
} from 'react';
import {
    CUSTOM_MUTE_UNITS,
    CustomMuteDialog,
    MAX_MUTE_HOURS,
} from '@/components/chat/custom-mute-dialog';
import { MutedIcon, PinIcon } from '@/components/chat/icons';
import {
    useChatChannel,
    useSlowMode,
    useStickyScroll,
} from '@/components/chat/hooks';
import { MessageRow } from '@/components/chat/message-row';
import { MutedUsersPanel } from '@/components/chat/muted-users-panel';
import { PinnedPanel } from '@/components/chat/pinned-panel';
import type {
    ChatMessage,
    Moderation,
    MutedUser,
    SlowMode,
} from '@/components/chat/types';
import { ArrowRightIcon, CrossIcon } from '@/components/icons';
import { useTranslation } from '@/lib/i18n/context';
import type { Auth } from '@/types/auth';

// Existing importers take these from here.
export { Avatar } from '@/components/chat/avatar';
export type {
    ChatMessage,
    ChatUser,
    MutedUser,
    SlowMode,
} from '@/components/chat/types';

const MAX_MESSAGE_LENGTH = 500;

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
    // Briefly flashed on whichever message was just jumped to from the
    // pinned list, so landing on it doesn't feel like nothing happened.
    const [justJumpedTo, setJustJumpedTo] = useState<number | null>(null);
    const messageRefs = useRef<Record<number, HTMLLIElement | null>>({});
    const composerRef = useRef<HTMLTextAreaElement>(null);

    const formatTime = (dateTime: string) =>
        new Date(dateTime).toLocaleTimeString(intlLocale, {
            hour: '2-digit',
            minute: '2-digit',
        });

    const isStaff = auth.user.role !== 'user';
    const {
        slowActive,
        cooldownSeconds,
        cooldownRemaining,
        activate: activateSlowMode,
        startCooldown,
    } = useSlowMode(slowMode, isStaff);
    const { scrollerRef, unseen, handleScroll, jumpToLatest } = useStickyScroll(
        messages,
        auth.user.id,
    );
    useChatChannel(channel, setMessages, setPinned, activateSlowMode);

    useEffect(() => {
        if (!moderationNotice) {
            return;
        }

        const id = setTimeout(() => setModerationNotice(null), 4000);

        return () => clearTimeout(id);
    }, [moderationNotice]);

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
                        startCooldown(cooldownSeconds);
                    }
                },
                onError: (errors) => {
                    // Slow mode: count the server's wait down live (the
                    // send button and the note below both follow it) rather
                    // than showing a fixed number that goes out of date.
                    const wait = Number(errors.slow_mode_wait);

                    if (wait > 0) {
                        startCooldown(wait);
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
                <PinnedPanel
                    pinned={pinned}
                    canUnpin={moderation !== undefined}
                    onClose={() => setPinnedOpen(false)}
                    onJump={jumpToMessage}
                    onUnpin={(id) => togglePin(id, true)}
                />
            )}

            {customMute && (
                <CustomMuteDialog
                    targetName={customMute.name}
                    amount={customAmount}
                    unit={customUnit}
                    valid={customMuteValid}
                    onAmountChange={setCustomAmount}
                    onUnitChange={setCustomUnit}
                    onSubmit={submitCustomMute}
                    onClose={() => setCustomMute(null)}
                />
            )}

            {mutedUsersOpen && mutedUsers.length > 0 && (
                <MutedUsersPanel
                    mutedUsers={mutedUsers}
                    onClose={() => setMutedUsersOpen(false)}
                    onUnmute={(id, name) => {
                        unmuteUser(id, name);
                        setMutedUsersOpen(false);
                    }}
                />
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
                                <MessageRow
                                    key={message.id}
                                    message={message}
                                    liRef={(el) => {
                                        messageRefs.current[message.id] = el;
                                    }}
                                    newDay={newDay}
                                    dayLabel={dayLabel(at)}
                                    startsGroup={startsGroup}
                                    isOwn={isOwn}
                                    canPenalise={canPenalise}
                                    hasModeration={moderation !== undefined}
                                    isMuted={mutedUsers.some(
                                        (u) => u.id === message.user.id,
                                    )}
                                    actionsOpen={openActionsFor === message.id}
                                    justJumped={justJumpedTo === message.id}
                                    formatTime={formatTime}
                                    onToggleActions={() =>
                                        setOpenActionsFor((current) =>
                                            current === message.id
                                                ? null
                                                : message.id,
                                        )
                                    }
                                    onReply={(target) => {
                                        startReply(target);
                                        setOpenActionsFor(null);
                                    }}
                                    onTogglePin={(id, isPinned) => {
                                        togglePin(id, isPinned);
                                        setOpenActionsFor(null);
                                    }}
                                    onCustomMute={(id, name) => {
                                        setCustomMute({ id, name });
                                        setCustomAmount('1');
                                        setCustomUnit(1);
                                        setOpenActionsFor(null);
                                    }}
                                    onUnmute={(id, name) => {
                                        unmuteUser(id, name);
                                        setOpenActionsFor(null);
                                    }}
                                    onDelete={(id) => {
                                        deleteMessage(id);
                                        setOpenActionsFor(null);
                                    }}
                                    onJump={jumpToMessage}
                                />
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
                                  seconds: cooldownSeconds,
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
