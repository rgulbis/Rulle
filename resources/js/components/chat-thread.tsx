import { usePage } from '@inertiajs/react';
import { useRef, useState } from 'react';
import { useTranslation } from '@/lib/i18n/context';
import type { Auth } from '@/types/auth';
import { Composer } from './chat/composer';
import { CustomMuteDialog } from './chat/custom-mute-dialog';
import { ChatHeader } from './chat/header';
import {
    useChatChannel,
    useMessageJump,
    useModerationActions,
    useSlowMode,
    useStickyScroll,
} from './chat/hooks';
import { MessageList } from './chat/message-list';
import { MutedUsersPanel } from './chat/muted-users-panel';
import { PinnedPanel } from './chat/pinned-panel';
import type {
    ChatMessage,
    Moderation,
    MutedUser,
    SlowMode,
} from './chat/types';

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
    /** The conversation is over: shown, but nothing more can be posted. */
    readOnly?: boolean;
    moderation?: Moderation;
    /** Who this room's moderator can currently unmute — fed from the
     * server rather than derived from messages, since a muted account
     * with nothing in the visible message window would otherwise have no
     * way to be found and unmuted at all. */
    mutedUsers?: MutedUser[];
    slowMode?: SlowMode;
};

/**
 * One chat room: header, message list, composer and the moderation panels.
 * It only wires the pieces in `components/chat/` together; the behaviour
 * lives in `chat/hooks.ts`.
 */
export default function ChatThread({
    channel,
    title,
    description,
    initialMessages,
    initialPinned = [],
    postUrl,
    muted = false,
    mutedUntil = null,
    readOnly = false,
    moderation,
    mutedUsers = [],
    slowMode,
}: Props) {
    const { auth } = usePage<{ auth: Auth }>().props;
    const { t } = useTranslation();
    const isStaff = auth.user.role !== 'user';

    const [pinnedOpen, setPinnedOpen] = useState(false);
    const [mutedUsersOpen, setMutedUsersOpen] = useState(false);
    const [replyingTo, setReplyingTo] = useState<ChatMessage | null>(null);
    const [customMute, setCustomMute] = useState<{
        id: number;
        name: string;
    } | null>(null);
    const composerRef = useRef<HTMLTextAreaElement>(null);

    const slow = useSlowMode(slowMode, isStaff);
    const { messages, pinned } = useChatChannel(
        channel,
        initialMessages,
        initialPinned,
        slow.activate,
    );
    const { scrollerRef, unseen, handleScroll, jumpToLatest } = useStickyScroll(
        messages,
        auth.user.id,
    );
    const { messageRefs, justJumpedTo, jumpToMessage } = useMessageJump(() =>
        setPinnedOpen(false),
    );
    const { notice, deleteMessage, muteUser, unmuteUser, togglePin } =
        useModerationActions(moderation);

    const startReply = (message: ChatMessage) => {
        setReplyingTo(message);
        composerRef.current?.focus();
    };

    return (
        <section className="relative flex min-h-0 min-w-0 flex-1 flex-col">
            <ChatHeader
                title={title}
                description={description}
                pinnedCount={pinned.length}
                pinnedOpen={pinnedOpen}
                onTogglePinned={() => setPinnedOpen((open) => !open)}
                mutedUsersCount={mutedUsers.length}
                mutedUsersOpen={mutedUsersOpen}
                onToggleMutedUsers={() => setMutedUsersOpen((open) => !open)}
            />

            {notice && (
                <p
                    role="status"
                    className="bg-accent text-accent-ink border-ink border-b-2 px-4 py-1.5 text-center text-sm font-medium"
                >
                    {notice}
                </p>
            )}

            {pinnedOpen && pinned.length > 0 && (
                <PinnedPanel
                    pinned={pinned}
                    canUnpin={moderation !== undefined}
                    onJump={jumpToMessage}
                    onUnpin={(messageId) => togglePin(messageId, true)}
                    onClose={() => setPinnedOpen(false)}
                />
            )}

            {customMute && (
                <CustomMuteDialog
                    userName={customMute.name}
                    onClose={() => setCustomMute(null)}
                    onConfirm={(hours, durationLabel) => {
                        muteUser(
                            customMute.id,
                            customMute.name,
                            hours,
                            durationLabel,
                        );
                        setCustomMute(null);
                    }}
                />
            )}

            {mutedUsersOpen && mutedUsers.length > 0 && (
                <MutedUsersPanel
                    mutedUsers={mutedUsers}
                    onUnmute={(user) => {
                        unmuteUser(user.id, user.name);
                        setMutedUsersOpen(false);
                    }}
                    onClose={() => setMutedUsersOpen(false)}
                />
            )}

            <MessageList
                title={title}
                messages={messages}
                ownUserId={auth.user.id}
                moderating={moderation !== undefined}
                mutedUsers={mutedUsers}
                scrollerRef={scrollerRef}
                messageRefs={messageRefs}
                justJumpedTo={justJumpedTo}
                onScroll={handleScroll}
                onJumpToMessage={jumpToMessage}
                onReply={startReply}
                onTogglePin={(message) => togglePin(message.id, message.pinned)}
                onCustomMute={(message) =>
                    setCustomMute({
                        id: message.user.id,
                        name: message.user.name,
                    })
                }
                onUnmute={(message) =>
                    unmuteUser(message.user.id, message.user.name)
                }
                onDelete={(message) => deleteMessage(message.id)}
            />

            {unseen > 0 && (
                <button
                    type="button"
                    onClick={jumpToLatest}
                    className="border-ink bg-accent text-accent-ink shadow-hard absolute bottom-28 left-1/2 z-10 -translate-x-1/2 border-2 px-4 py-2 text-sm font-semibold"
                >
                    {t('chatThread.jumpToNew', { count: unseen })}
                </button>
            )}

            <Composer
                title={title}
                postUrl={postUrl}
                readOnly={readOnly}
                muted={muted}
                mutedUntil={mutedUntil}
                isStaff={isStaff}
                slow={slow}
                replyingTo={replyingTo}
                onCancelReply={() => setReplyingTo(null)}
                textareaRef={composerRef}
            />
        </section>
    );
}
