import { useTranslation } from '@/lib/i18n/context';
import { MutedIcon, PinIcon } from './icons';

type Props = {
    title: string;
    description?: string;
    pinnedCount: number;
    pinnedOpen: boolean;
    onTogglePinned: () => void;
    mutedUsersCount: number;
    mutedUsersOpen: boolean;
    onToggleMutedUsers: () => void;
};

export function ChatHeader({
    title,
    description,
    pinnedCount,
    pinnedOpen,
    onTogglePinned,
    mutedUsersCount,
    mutedUsersOpen,
    onToggleMutedUsers,
}: Props) {
    const { t } = useTranslation();

    return (
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
            {pinnedCount > 0 && (
                <button
                    type="button"
                    onClick={onTogglePinned}
                    aria-expanded={pinnedOpen}
                    aria-controls="pinned-messages"
                    className={`ml-auto flex min-h-11 shrink-0 items-center gap-2 border-2 px-3 text-sm font-semibold transition ${
                        pinnedOpen
                            ? 'border-ink bg-accent text-accent-ink'
                            : 'border-line hover:border-ink'
                    }`}
                >
                    <PinIcon />
                    {t('chatThread.pinnedCount', { count: pinnedCount })}
                </button>
            )}
            {mutedUsersCount > 0 && (
                <button
                    type="button"
                    onClick={onToggleMutedUsers}
                    aria-expanded={mutedUsersOpen}
                    aria-controls="muted-users"
                    className={`flex min-h-11 shrink-0 items-center gap-2 border-2 px-3 text-sm font-semibold transition ${
                        pinnedCount === 0 ? 'ml-auto' : ''
                    } ${
                        mutedUsersOpen
                            ? 'border-ink bg-accent text-accent-ink'
                            : 'border-line hover:border-ink'
                    }`}
                >
                    <MutedIcon />
                    {t('chatThread.mutedUsersCount', {
                        count: mutedUsersCount,
                    })}
                </button>
            )}
        </header>
    );
}
