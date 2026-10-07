import { CrossIcon } from '@/components/icons';
import { useTranslation } from '@/lib/i18n/context';
import { Avatar } from './avatar';
import { MoreIcon, PinIcon, ReplyIcon } from './icons';
import type { ChatMessage } from './types';

type Props = {
    message: ChatMessage;
    at: Date;
    newDay: boolean;
    startsGroup: boolean;
    isOwn: boolean;
    /** Whether the viewer may pin/unpin (a room moderator). */
    canModerate: boolean;
    /** Whether the viewer may delete/mute this message's author. Mirrors
     * App\Support\ChatModeration: the inline moderators are employees, who
     * may only delete and mute customers — never staff or admins. */
    canPenalise: boolean;
    authorIsMuted: boolean;
    jumpedTo: boolean;
    actionsOpen: boolean;
    onToggleActions: () => void;
    onCloseActions: () => void;
    onReply: () => void;
    onTogglePin: () => void;
    onCustomMute: () => void;
    onUnmute: () => void;
    onDelete: () => void;
    onJumpToReply: (messageId: number) => void;
    liRef: (element: HTMLLIElement | null) => void;
};

const ACTION_BUTTON =
    'bg-paper hover:bg-accent hover:text-accent-ink min-h-9 flex-1 px-2.5';

export function MessageRow({
    message,
    at,
    newDay,
    startsGroup,
    isOwn,
    canModerate,
    canPenalise,
    authorIsMuted,
    jumpedTo,
    actionsOpen,
    onToggleActions,
    onCloseActions,
    onReply,
    onTogglePin,
    onCustomMute,
    onUnmute,
    onDelete,
    onJumpToReply,
    liRef,
}: Props) {
    const { t, intlLocale } = useTranslation();

    const formatTime = (dateTime: string) =>
        new Date(dateTime).toLocaleTimeString(intlLocale, {
            hour: '2-digit',
            minute: '2-digit',
        });

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

    // Runs an action and folds the actions bar away afterwards.
    const then = (action: () => void) => () => {
        action();
        onCloseActions();
    };

    return (
        <li ref={liRef}>
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
                    startsGroup ? 'mt-3 pt-1 pb-0.5' : 'py-0.5'
                } ${actionsOpen ? 'z-30' : ''} ${message.pinned ? 'border-accent' : 'border-transparent'} ${
                    jumpedTo ? 'bg-accent/20' : ''
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
                            onClick={() => onJumpToReply(message.reply_to!.id)}
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

                {/* Reply is for everyone, so this bar always exists now —
                pin/mute/delete are the only parts still gated by moderation
                rights. The pin/mute/delete bar normally only reveals on
                hover/focus; there's no hover on a touchscreen, so below the
                `lg` breakpoint this button toggles it instead. */}
                <button
                    type="button"
                    onClick={onToggleActions}
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
                        onClick={then(onReply)}
                        className={`${ACTION_BUTTON} flex items-center justify-center gap-1.5`}
                    >
                        <ReplyIcon size={14} />
                        {t('chatThread.reply')}
                    </button>
                    {canModerate && (
                        <button
                            type="button"
                            onClick={then(onTogglePin)}
                            className={`${ACTION_BUTTON} flex items-center justify-center gap-1.5`}
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
                                onClick={then(onCustomMute)}
                                className={ACTION_BUTTON}
                            >
                                {t('chatThread.muteCustom')}
                            </button>
                            {authorIsMuted && (
                                <button
                                    type="button"
                                    onClick={then(onUnmute)}
                                    className={ACTION_BUTTON}
                                >
                                    {t('chatThread.unmute')}
                                </button>
                            )}
                            <button
                                type="button"
                                onClick={then(onDelete)}
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
