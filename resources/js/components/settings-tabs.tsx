import { Link, usePage } from '@inertiajs/react';
import { useTranslation } from '@/lib/i18n/context';

/** Profile | Password switcher shared by the account settings pages. */
export default function SettingsTabs() {
    const { t } = useTranslation();
    const { url } = usePage();

    const tabs = [
        { href: '/settings/profile', label: t('settings.tabs.profile') },
        { href: '/settings/password', label: t('settings.tabs.password') },
    ];

    return (
        <nav aria-label={t('nav.account')} className="mb-6 flex gap-2">
            {tabs.map((tab) => {
                const current = url.startsWith(tab.href);

                return (
                    <Link
                        key={tab.href}
                        href={tab.href}
                        aria-current={current ? 'page' : undefined}
                        className={`flex min-h-11 items-center border-2 px-4 font-semibold transition ${
                            current
                                ? 'border-ink bg-accent text-accent-ink'
                                : 'border-line hover:border-ink'
                        }`}
                    >
                        {tab.label}
                    </Link>
                );
            })}
        </nav>
    );
}
