import { Link, usePage } from '@inertiajs/react';
import { ReactNode } from 'react';
import { Avatar } from '@/components/chat-thread';
import { useTranslation } from '@/lib/i18n/context';
import type { Auth } from '@/types/auth';

export type ChatGroup = {
    id: number;
    starts_at: string;
    ends_at: string;
};

/** A reservation group chat's channel name, e.g. "2. okt. 16:00". */
export function useGroupChannelName() {
    const { intlLocale } = useTranslation();

    return (group: Pick<ChatGroup, 'starts_at'>) =>
        new Date(group.starts_at).toLocaleString(intlLocale, {
            day: 'numeric',
            month: 'short',
            hour: '2-digit',
            minute: '2-digit',
        });
}

/**
 * Discord-style chat frame: a channel list (the park-wide chat plus the
 * user's reservation group chats) beside the open conversation. On phones
 * the list collapses into a row of channel tabs above it.
 */
export default function ChatShell({
    active,
    groups,
    children,
}: {
    /** 'general' for the park-wide chat, or the open group's reservation id. */
    active: 'general' | number;
    groups: ChatGroup[];
    children: ReactNode;
}) {
    const { t } = useTranslation();
    const { auth } = usePage<{ auth: Auth }>().props;
    const groupName = useGroupChannelName();
    const isCustomer = auth.user.role === 'user';

    const channels = [
        { key: 'general' as const, href: '/chat', name: t('chat.general') },
        ...groups.map((group) => ({
            key: group.id,
            href: `/reservations/${group.id}/chat`,
            name: groupName(group),
        })),
    ];

    const channelLink = (
        channel: (typeof channels)[number],
        compact = false,
    ) => {
        const current = channel.key === active;

        return (
            <Link
                key={channel.key}
                href={channel.href}
                aria-current={current ? 'page' : undefined}
                className={`flex min-h-11 items-center gap-2 border-2 px-3 font-medium whitespace-nowrap transition ${
                    current
                        ? 'border-ink bg-accent text-accent-ink font-semibold'
                        : 'text-muted hover:text-ink hover:border-line border-transparent'
                } ${compact ? 'shrink-0' : ''}`}
            >
                <span aria-hidden="true" className="text-lg opacity-60">
                    #
                </span>
                <span className="truncate">{channel.name}</span>
            </Link>
        );
    };

    return (
        <div className="flex min-h-0 flex-1">
            <aside className="border-ink bg-paper hidden w-72 shrink-0 flex-col border-r-2 md:flex">
                <p className="font-display border-ink flex min-h-14 items-center border-b-2 px-5 text-3xl font-black uppercase">
                    {t('chat.title')}
                </p>
                <nav
                    aria-label={t('chat.channels')}
                    className="flex min-h-0 flex-1 flex-col gap-6 overflow-y-auto p-3"
                >
                    <div className="flex flex-col gap-1">
                        <p className="text-muted px-3 pb-1 font-mono text-xs font-semibold tracking-[0.12em] uppercase">
                            {t('chat.channels')}
                        </p>
                        {channelLink(channels[0])}
                    </div>

                    {isCustomer && (
                        <div className="flex flex-col gap-1">
                            <p className="text-muted px-3 pb-1 font-mono text-xs font-semibold tracking-[0.12em] uppercase">
                                {t('chat.yourGroups')}
                            </p>
                            {channels
                                .slice(1)
                                .map((channel) => channelLink(channel))}
                            {groups.length === 0 && (
                                <p className="text-muted px-3 text-sm">
                                    {t('chat.noGroups')}{' '}
                                    <Link
                                        href="/reservations"
                                        className="text-ink font-semibold underline underline-offset-4"
                                    >
                                        {t('dashboard.bookSlot')}
                                    </Link>
                                </p>
                            )}
                        </div>
                    )}
                </nav>
                <div className="border-ink flex items-center gap-3 border-t-2 px-4 py-3">
                    <Avatar user={auth.user} />
                    <div className="min-w-0">
                        <p className="truncate font-semibold">
                            {auth.user.name}
                        </p>
                        <Link
                            href="/settings/profile"
                            className="text-muted hover:text-ink text-sm underline underline-offset-2"
                        >
                            {t('chat.editProfile')}
                        </Link>
                    </div>
                </div>
            </aside>

            <div className="flex min-h-0 min-w-0 flex-1 flex-col">
                {channels.length > 1 && (
                    <nav
                        aria-label={t('chat.channels')}
                        className="border-ink flex gap-2 overflow-x-auto border-b-2 px-3 py-2 md:hidden"
                    >
                        {channels.map((channel) => channelLink(channel, true))}
                    </nav>
                )}
                {children}
            </div>
        </div>
    );
}
