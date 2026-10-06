import { Avatar } from '@/components/chat/avatar';
import type { ChatMessage } from '@/components/chat/types';
import { CrossIcon } from '@/components/icons';
import { useTranslation } from '@/lib/i18n/context';

export function PinnedPanel({
    pinned,
    canUnpin,
    onClose,
    onJump,
    onUnpin,
}: {
    pinned: ChatMessage[];
    canUnpin: boolean;
    onClose: () => void;
    onJump: (messageId: number) => void;
    onUnpin: (messageId: number) => void;
}) {
    const { t } = useTranslation();

    return (
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
                    onClick={() => onClose()}
                    aria-label={t('chatThread.closePinned')}
                    className="flex size-11 items-center justify-center"
                >
                    <CrossIcon size={18} />
                </button>
            </div>
            <ul className="divide-line flex flex-col divide-y overflow-y-auto">
                {pinned.map((message) => (
                    <li key={message.id} className="flex gap-3 px-4 py-3">
                        <Avatar user={message.user} size="sm" />
                        <button
                            type="button"
                            onClick={() => onJump(message.id)}
                            className="hover:bg-line/40 min-w-0 flex-1 rounded-none text-left"
                        >
                            <p className="text-sm font-semibold">
                                {message.user.name}
                            </p>
                            <p className="text-base break-words whitespace-pre-wrap">
                                {message.body}
                            </p>
                        </button>
                        {canUnpin && (
                            <button
                                type="button"
                                onClick={() => onUnpin(message.id)}
                                className="shrink-0 self-start text-sm font-semibold underline underline-offset-4"
                            >
                                {t('chatThread.unpin')}
                            </button>
                        )}
                    </li>
                ))}
            </ul>
        </div>
    );
}
