import type { Ref } from 'react';
import { Avatar } from '@/components/chat/avatar';
import { MoreIcon, PinIcon, ReplyIcon } from '@/components/chat/icons';
import type { ChatMessage } from '@/components/chat/types';
import { CrossIcon } from '@/components/icons';
import { useTranslation } from '@/lib/i18n/context';

/**
 * One message in the list, with its day divider, hover/tap action bar and
 * reply preview. Pure presentation: everything that changes state is a
 * callback owned by ChatThread.
 */
export function MessageRow({
    message,
    liRef,
    newDay,
    dayLabel,
    startsGroup,
    isOwn,
    canPenalise,
    hasModeration,
    isMuted,
    actionsOpen,
    justJumped,
    formatTime,
    onToggleActions,
    onReply,
    onTogglePin,
    onCustomMute,
    onUnmute,
    onDelete,
    onJump,
}: {
    message: ChatMessage;
    liRef: Ref<HTMLLIElement>;
    newDay: boolean;
    dayLabel: string;
    startsGroup: boolean;
    isOwn: boolean;
    /** Whether the viewer may delete / mute this message's author. */
    canPenalise: boolean;
    hasModeration: boolean;
    /** The author is currently muted (so "unmute" is offered). */
    isMuted: boolean;
    actionsOpen: boolean;
    justJumped: boolean;
    formatTime: (dateTime: string) => string;
    onToggleActions: () => void;
    onReply: (message: ChatMessage) => void;
    onTogglePin: (messageId: number, isPinned: boolean) => void;
    onCustomMute: (userId: number, userName: string) => void;
    onUnmute: (userId: number, userName: string) => void;
    onDelete: (messageId: number) => void;
    onJump: (messageId: number) => void;
}) {
    const { t } = useTranslation();

    return (
        <li ref={liRef}>
            {newDay && (
                <div
                    role="separator"
                    className="text-muted my-3 flex items-center gap-3 px-4 font-mono text-xs font-semibold tracking-[0.08em] uppercase lg:px-6"
                >
                    <span className="bg-line h-0.5 flex-1" />
                    {dayLabel}
                    <span className="bg-line h-0.5 flex-1" />
                </div>
            )}
            <div
                className={`group hover:bg-paper focus-within:bg-paper relative flex gap-4 border-l-4 px-4 transition-colors lg:px-6 ${
                    startsGroup ? 'mt-3 pt-1 pb-0.5' : 'py-0.5'
                } ${actionsOpen ? 'z-30' : ''} ${message.pinned ? 'border-accent' : 'border-transparent'} ${
                    justJumped ? 'bg-accent/20' : ''
                }`}
            >
                <div className="w-10 shrink-0">
                    {startsGroup ? (
                        <Avatar user={message.user} />
                    ) : (
                        <time
                            dateTime={message.created_at}
                            className="text-faint hidden pt-1 text-right font-mono text-[11px] group-hover:block"
                        >
                            {formatTime(message.created_at)}
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
                                    ({t('chatThread.you')})
                                </span>
                            )}
                            {message.user.role !== 'user' && (
                                <span className="bg-ink text-ground px-1.5 py-0.5 font-mono text-[11px] font-semibold tracking-[0.08em] uppercase">
                                    {message.user.role === 'admin'
                                        ? t('chatThread.roleAdmin')
                                        : t('chatThread.roleStaff')}
                                </span>
                            )}
                            <time
                                dateTime={message.created_at}
                                className="text-muted font-mono text-xs"
                            >
                                {formatTime(message.created_at)}
                            </time>
                        </p>
                    )}
                    {message.reply_to && (
                        <button
                            type="button"
                            onClick={() => onJump(message.reply_to!.id)}
                            className="border-line hover:border-ink mb-1 flex max-w-full items-baseline gap-1.5 border-l-2 py-0.5 pl-2 text-left text-sm"
                        >
                            <span className="text-muted shrink-0 font-semibold">
                                {message.reply_to.user.name}
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
                    onClick={() => onToggleActions()}
                    aria-label={t('chatThread.moreActions')}
                    aria-expanded={actionsOpen}
                    className={`absolute top-1 right-1 flex size-8 items-center justify-center lg:hidden ${
                        actionsOpen
                            ? 'border-ink bg-paper z-40 border-2'
                            : 'text-faint z-20'
                    }`}
                >
                    {actionsOpen ? <CrossIcon size={14} /> : <MoreIcon />}
                </button>
                <div
                    className={`border-ink bg-paper shadow-hard bg-line absolute top-10 right-1 z-30 max-w-[calc(100%-0.5rem)] flex-wrap items-stretch gap-0.5 border-2 text-xs font-semibold lg:-top-4 lg:right-4 lg:group-focus-within:flex lg:group-hover:flex ${
                        actionsOpen ? 'flex' : 'hidden'
                    }`}
                >
                    <button
                        type="button"
                        onClick={() => onReply(message)}
                        className="bg-paper hover:bg-accent hover:text-accent-ink flex min-h-9 flex-1 items-center justify-center gap-1.5 px-2.5"
                    >
                        <ReplyIcon size={14} />
                        {t('chatThread.reply')}
                    </button>
                    {hasModeration && (
                        <button
                            type="button"
                            onClick={() =>
                                onTogglePin(message.id, message.pinned)
                            }
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
                                onClick={() =>
                                    onCustomMute(
                                        message.user.id,
                                        message.user.name,
                                    )
                                }
                                className="bg-paper hover:bg-accent hover:text-accent-ink min-h-9 flex-1 px-2.5"
                            >
                                {t('chatThread.muteCustom')}
                            </button>
                            {isMuted && (
                                <button
                                    type="button"
                                    onClick={() =>
                                        onUnmute(
                                            message.user.id,
                                            message.user.name,
                                        )
                                    }
                                    className="bg-paper hover:bg-accent hover:text-accent-ink min-h-9 flex-1 px-2.5"
                                >
                                    {t('chatThread.unmute')}
                                </button>
                            )}
                            <button
                                type="button"
                                onClick={() => onDelete(message.id)}
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
}
