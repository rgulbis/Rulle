import { router } from '@inertiajs/react';
import {
    useCallback,
    useEffect,
    useLayoutEffect,
    useRef,
    useState,
} from 'react';
import { useTranslation } from '@/lib/i18n/context';
import type { ChatMessage, Moderation, SlowMode } from './types';

/**
 * Keeps the message and pinned lists in step with the room's websocket
 * channel: new, deleted and (un)pinned messages, plus slow mode switching on.
 */
export function useChatChannel(
    channel: string,
    initialMessages: ChatMessage[],
    initialPinned: ChatMessage[],
    onSlowModeActivated: (slowMode: SlowMode) => void,
) {
    const [messages, setMessages] = useState(initialMessages);
    const [pinned, setPinned] = useState(initialPinned);
    // Read through a ref so a new callback each render doesn't re-subscribe.
    const slowModeHandler = useRef(onSlowModeActivated);

    useEffect(() => {
        slowModeHandler.current = onSlowModeActivated;
    });

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
            slowModeHandler.current(e);
        });

        return () => {
            window.Echo.leave(channel);
        };
    }, [channel]);

    return { messages, pinned };
}

/**
 * Slow mode is tracked as absolute client-side end times, derived from the
 * durations the server sends, so a skewed clock can't stretch it. Staff are
 * never held back by the cooldown.
 */
export function useSlowMode(initial: SlowMode | undefined, isStaff: boolean) {
    const [now, setNow] = useState(() => Date.now());
    const [slow, setSlow] = useState(() => ({
        endsAt: Date.now() + (initial?.remaining_seconds ?? 0) * 1000,
        cooldownSeconds: initial?.cooldown_seconds ?? 0,
    }));
    const [cooldownEndsAt, setCooldownEndsAt] = useState(0);

    const active = slow.endsAt > now;
    const cooldownRemaining =
        !isStaff && cooldownEndsAt > now
            ? Math.ceil((cooldownEndsAt - now) / 1000)
            : 0;
    const needsTicker = active || cooldownEndsAt > now;

    useEffect(() => {
        if (!needsTicker) {
            return;
        }

        const id = setInterval(() => setNow(Date.now()), 1000);

        return () => clearInterval(id);
    }, [needsTicker]);

    /** The server announced that slow mode just switched on. */
    const activate = useCallback((e: SlowMode) => {
        const current = Date.now();

        setSlow({
            endsAt: current + e.remaining_seconds * 1000,
            cooldownSeconds: e.cooldown_seconds,
        });
        setNow(current);
    }, []);

    /** Counts a wait down live, e.g. the one the server asked for. */
    const startCooldown = (seconds: number) => {
        const current = Date.now();

        setCooldownEndsAt(current + seconds * 1000);
        setNow(current);
    };

    /** Call after a message was accepted. */
    const afterSend = () => {
        if (active && !isStaff) {
            startCooldown(slow.cooldownSeconds);
        }
    };

    return {
        active,
        cooldownSeconds: slow.cooldownSeconds,
        cooldownRemaining,
        activate,
        startCooldown,
        afterSend,
    };
}

export type SlowModeState = ReturnType<typeof useSlowMode>;

/**
 * Only the message list scrolls - never the page. It follows new messages
 * while you're at the bottom (or when you sent one yourself), and otherwise
 * leaves you where you are and counts what you haven't seen.
 */
export function useStickyScroll(messages: ChatMessage[], ownUserId: number) {
    const scrollerRef = useRef<HTMLDivElement>(null);
    const stickToBottom = useRef(true);
    const seenCount = useRef(messages.length);
    const [unseen, setUnseen] = useState(0);

    useLayoutEffect(() => {
        const scroller = scrollerRef.current;

        if (!scroller) {
            return;
        }

        const added = messages.length - seenCount.current;
        seenCount.current = messages.length;
        const last = messages[messages.length - 1];
        const ownLatest = added > 0 && last?.user.id === ownUserId;

        if (stickToBottom.current || ownLatest) {
            scroller.scrollTop = scroller.scrollHeight;
            setUnseen(0);
        } else if (added > 0) {
            setUnseen((n) => n + added);
        }
    }, [messages, ownUserId]);

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

    return { scrollerRef, unseen, handleScroll, jumpToLatest };
}

/**
 * Scrolls to a message (from the pinned list or a reply quote) and briefly
 * flashes it so landing there doesn't feel like nothing happened.
 */
export function useMessageJump(onJump: () => void) {
    const messageRefs = useRef<Record<number, HTMLLIElement | null>>({});
    const [justJumpedTo, setJustJumpedTo] = useState<number | null>(null);

    // A pinned message can be much older than the ~100 recent ones this
    // page loaded, in which case it simply isn't in the DOM to scroll to -
    // silently doing nothing is the right fallback there rather than an
    // error, since there's nowhere sensible to jump.
    const jumpToMessage = (messageId: number) => {
        onJump();
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

    return { messageRefs, justJumpedTo, jumpToMessage };
}

/**
 * Delete / pin / mute / unmute requests for a room's moderators, and the
 * short confirmation shown after muting or unmuting.
 */
export function useModerationActions(moderation: Moderation | undefined) {
    const { t } = useTranslation();
    const [notice, setNotice] = useState<string | null>(null);

    useEffect(() => {
        if (!notice) {
            return;
        }

        const id = setTimeout(() => setNotice(null), 4000);

        return () => clearTimeout(id);
    }, [notice]);

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
                    setNotice(
                        t('chatThread.muteSuccess', {
                            name: userName,
                            duration: durationLabel,
                        }),
                    ),
                // Reachable in practice only via a stale UI (the buttons
                // that lead here are already hidden for a disallowed
                // target) - a generic message is enough for that edge case.
                onError: () => setNotice(t('chatThread.muteFailed')),
            },
        );
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
                    setNotice(
                        t('chatThread.unmuteSuccess', { name: userName }),
                    ),
                onError: () => setNotice(t('chatThread.unmuteFailed')),
            },
        );
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

    return { notice, deleteMessage, muteUser, unmuteUser, togglePin };
}
