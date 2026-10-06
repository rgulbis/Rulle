import type { MutedUser } from '@/components/chat/types';
import { CrossIcon } from '@/components/icons';
import { useTranslation } from '@/lib/i18n/context';

export function MutedUsersPanel({
    mutedUsers,
    onClose,
    onUnmute,
}: {
    mutedUsers: MutedUser[];
    onClose: () => void;
    onUnmute: (userId: number, userName: string) => void;
}) {
    const { t, intlLocale } = useTranslation();

    return (
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
                    onClick={() => onClose()}
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
                            onClick={() =>
                                onUnmute(mutedUser.id, mutedUser.name)
                            }
                            className="shrink-0 self-start text-sm font-semibold underline underline-offset-4"
                        >
                            {t('chatThread.unmute')}
                        </button>
                    </li>
                ))}
            </ul>
        </div>
    );
}
