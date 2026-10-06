import {
    useEffect,
    useLayoutEffect,
    useRef,
    useState,
    type Dispatch,
    type SetStateAction,
} from 'react';
import type { ChatMessage, SlowMode } from '@/components/chat/types';

/**
 * Only the message list scrolls — never the page. It follows new messages
 * while you're at the bottom (or when you sent one yourself), and otherwise
 * leaves you where you are and counts what you haven't seen yet.
 */
export function useStickyScroll(messages: ChatMessage[], ownUserId: number) {
    const scrollerRef = useRef<HTMLDivElement>(null);
    const stickToBottom = useRef(true);
    const seenCount = useRef(messages.length);
    // New messages that arrived while scrolled up reading older ones.
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
 * Slow mode is tracked as absolute client-side end times, derived from the
 * durations the server sends, so a skewed clock can't stretch it. Customers
 * (not staff) also get a per-message cooldown after posting.
 */
export function useSlowMode(initial: SlowMode | undefined, isStaff: boolean) {
    const [now, setNow] = useState(() => Date.now());
    const [slow, setSlow] = useState(() => ({
        endsAt: Date.now() + (initial?.remaining_seconds ?? 0) * 1000,
        cooldownSeconds: initial?.cooldown_seconds ?? 0,
    }));
    const [cooldownEndsAt, setCooldownEndsAt] = useState(0);

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

    /** The server switched slow mode on (live event). */
    const activate = (event: SlowMode) => {
        const current = Date.now();

        setSlow({
            endsAt: current + event.remaining_seconds * 1000,
            cooldownSeconds: event.cooldown_seconds,
        });
        setNow(current);
    };

    /** Block sending for `seconds` from now. */
    const startCooldown = (seconds: number) => {
        const current = Date.now();

        setCooldownEndsAt(current + seconds * 1000);
        setNow(current);
    };

    return {
        slowActive,
        cooldownSeconds: slow.cooldownSeconds,
        cooldownRemaining,
        activate,
        startCooldown,
    };
}

/**
 * Subscribes to a room's live events for as long as the component is mounted.
 */
export function useChatChannel(
    channel: string,
    setMessages: Dispatch<SetStateAction<ChatMessage[]>>,
    setPinned: Dispatch<SetStateAction<ChatMessage[]>>,
    onSlowMode: (event: SlowMode) => void,
) {
    // The latest callback without re-subscribing every render.
    const slowModeHandler = useRef(onSlowMode);
    slowModeHandler.current = onSlowMode;

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
    }, [channel, setMessages, setPinned]);
}
