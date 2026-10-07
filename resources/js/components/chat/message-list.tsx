import { RefObject, useMemo, useState } from 'react';
import { useTranslation } from '@/lib/i18n/context';
import { MessageRow } from './message-row';
import type { ChatMessage, MutedUser } from './types';

// Discord-style grouping: consecutive messages from the same person within
// a few minutes share one name/avatar header, and a divider marks each new day.
const GROUP_GAP_MS = 5 * 60 * 1000;

type Props = {
    title: string;
    messages: ChatMessage[];
    ownUserId: number;
    moderating: boolean;
    mutedUsers: MutedUser[];
    scrollerRef: RefObject<HTMLDivElement | null>;
    messageRefs: RefObject<Record<number, HTMLLIElement | null>>;
    justJumpedTo: number | null;
    onScroll: () => void;
    onJumpToMessage: (messageId: number) => void;
    onReply: (message: ChatMessage) => void;
    onTogglePin: (message: ChatMessage) => void;
    onCustomMute: (message: ChatMessage) => void;
    onUnmute: (message: ChatMessage) => void;
    onDelete: (message: ChatMessage) => void;
};

export function MessageList({
    title,
    messages,
    ownUserId,
    moderating,
    mutedUsers,
    scrollerRef,
    messageRefs,
    justJumpedTo,
    onScroll,
    onJumpToMessage,
    onReply,
    onTogglePin,
    onCustomMute,
    onUnmute,
    onDelete,
}: Props) {
    const { t } = useTranslation();
    const [openActionsFor, setOpenActionsFor] = useState<number | null>(null);

    const rows = useMemo(
        () =>
            messages.map((message, i) => {
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
            }),
        [messages],
    );

    return (
        <div
            ref={scrollerRef}
            onScroll={onScroll}
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
                            : t('chatThread.channelStart', { channel: title })}
                    </p>
                </div>

                <ol aria-label={title} className="flex flex-col">
                    {rows.map(({ message, at, newDay, startsGroup }) => {
                        const isOwn = message.user.id === ownUserId;

                        return (
                            <MessageRow
                                key={message.id}
                                message={message}
                                at={at}
                                newDay={newDay}
                                startsGroup={startsGroup}
                                isOwn={isOwn}
                                canModerate={moderating}
                                canPenalise={
                                    moderating &&
                                    !isOwn &&
                                    message.user.role === 'user'
                                }
                                authorIsMuted={mutedUsers.some(
                                    (u) => u.id === message.user.id,
                                )}
                                jumpedTo={justJumpedTo === message.id}
                                actionsOpen={openActionsFor === message.id}
                                onToggleActions={() =>
                                    setOpenActionsFor((current) =>
                                        current === message.id
                                            ? null
                                            : message.id,
                                    )
                                }
                                onCloseActions={() => setOpenActionsFor(null)}
                                onReply={() => onReply(message)}
                                onTogglePin={() => onTogglePin(message)}
                                onCustomMute={() => onCustomMute(message)}
                                onUnmute={() => onUnmute(message)}
                                onDelete={() => onDelete(message)}
                                onJumpToReply={onJumpToMessage}
                                liRef={(el) => {
                                    messageRefs.current[message.id] = el;
                                }}
                            />
                        );
                    })}
                </ol>
            </div>
        </div>
    );
}
