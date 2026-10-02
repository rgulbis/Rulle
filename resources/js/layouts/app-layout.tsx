import { Head, Link, router, usePage } from '@inertiajs/react';
import { PropsWithChildren, ReactNode, useEffect, useState } from 'react';
import { buttonClasses } from '@/components/form-controls';
import { MenuIcon, CrossIcon } from '@/components/icons';
import LanguageToggle from '@/components/language-toggle';
import { useTranslation } from '@/lib/i18n/context';
import { cn } from '@/lib/utils';
import type { Park } from '@/types';
import type { User } from '@/types/auth';

function NavLink({
    href,
    children,
    external = false,
    activePrefix,
}: {
    href: string;
    children: ReactNode;
    external?: boolean;
    /** Also highlight for every page under this path (e.g. /settings). */
    activePrefix?: string;
}) {
    const { url } = usePage();
    const active =
        url === href ||
        url.startsWith(`${href}?`) ||
        url.startsWith(`${href}/`) ||
        (activePrefix !== undefined && url.startsWith(`${activePrefix}/`));
    // The row itself stays full-width so mobile keeps a generous tap target,
    // but the underline sits on an inner inline element — otherwise a
    // column-flex mobile menu stretches every link to the container's width
    // and the indicator runs edge-to-edge under the label instead of hugging
    // it like it does in the row-flex desktop nav.
    const rowClassName = 'flex min-h-11 w-full items-center text-base';
    const labelClassName = `inline-flex items-center gap-2 border-b-[3px] ${
        active
            ? 'border-accent font-semibold text-ink'
            : 'border-transparent font-medium text-ink group-hover:border-line'
    }`;

    // The Filament admin panel is a separate app, not an Inertia page.
    return external ? (
        <a href={href} className={`group ${rowClassName}`}>
            <span className={labelClassName}>{children}</span>
        </a>
    ) : (
        <Link
            href={href}
            className={`group ${rowClassName}`}
            aria-current={active ? 'page' : undefined}
        >
            <span className={labelClassName}>{children}</span>
        </Link>
    );
}

export function LiveDot({ className = '' }: { className?: string }) {
    return (
        <span
            aria-hidden="true"
            className={`animate-live-pulse bg-live inline-block size-2 rounded-full ${className}`}
        />
    );
}

export function OpenStatus({ park }: { park: Park }) {
    const { t } = useTranslation();

    return (
        <p className="flex shrink-0 items-center gap-2 font-mono text-xs font-semibold tracking-[0.08em] whitespace-nowrap uppercase">
            <span
                aria-hidden="true"
                className={`size-2.5 shrink-0 ${park.is_open ? 'bg-ok' : 'bg-faint'}`}
            />
            {park.is_open
                ? t('park.openUntil', { time: park.closing_time })
                : t('park.closedOpensAt', { time: park.opening_time })}
        </p>
    );
}

export default function AppLayout({
    children,
    theme,
    fullHeight = false,
}: PropsWithChildren<{
    // Pages can force a theme (the livestream is always dark). Otherwise
    // staff and admins get the dark theme everywhere — their side of the
    // site matches the Filament admin panel — and customers get light.
    theme?: 'light' | 'dark';
    // App-style pages (chat) fill exactly one screen: no footer, no page
    // scroll — their own panels scroll instead.
    fullHeight?: boolean;
}>) {
    // Wider than the app-wide `Auth` type on purpose: guests land on this
    // layout too (the home and livestream pages), so the user really can be
    // null here, unlike every other page that renders it.
    const { auth, park } = usePage<{
        auth: { user: User | null };
        park: Park;
    }>().props;
    const user = auth.user;
    const { t } = useTranslation();
    const [menuOpen, setMenuOpen] = useState(false);

    const isStaff = user?.role === 'admin' || user?.role === 'employee';
    const dark = theme ? theme === 'dark' : isStaff;

    // The theme class only covers this layout's wrapper div, so the browser
    // chrome (Safari's top/bottom bars, iOS overscroll) would otherwise keep
    // tinting itself from the light page background. Put the theme on <html>
    // too, so the body and canvas backgrounds (which Safari samples) follow.
    const chromeColor = dark ? '#111113' : '#eceae4';
    useEffect(() => {
        const root = document.documentElement;
        root.classList.toggle('theme-dark', dark);

        return () => {
            root.classList.remove('theme-dark');
        };
    }, [dark]);

    const links = (
        <>
            {user?.role === 'user' && (
                <>
                    <NavLink href="/dashboard">{t('nav.dashboard')}</NavLink>
                    <NavLink href="/subscriptions">
                        {t('nav.subscriptions')}
                    </NavLink>
                    <NavLink href="/reservations">
                        {t('nav.reservations')}
                    </NavLink>
                </>
            )}
            {isStaff && <NavLink href="/staff/scan">{t('nav.scan')}</NavLink>}
            {user && <NavLink href="/chat">{t('nav.chat')}</NavLink>}
            <NavLink href="/livestream">
                <LiveDot />
                {t('nav.livestream')}
            </NavLink>
            {user?.role === 'admin' && (
                <NavLink href="/admin" external>
                    {t('nav.admin')}
                </NavLink>
            )}
        </>
    );

    const account = user ? (
        <>
            <NavLink href="/settings/profile" activePrefix="/settings">
                {t('nav.account')}
            </NavLink>
            <button
                type="button"
                onClick={() => router.post('/logout')}
                className="text-ink min-h-11 shrink-0 text-base font-medium whitespace-nowrap underline underline-offset-4"
            >
                {t('nav.logOut')}
            </button>
        </>
    ) : (
        <>
            <NavLink href="/login">{t('nav.logIn')}</NavLink>
            <Link href="/register" className={buttonClasses('accent')}>
                {t('nav.signUp')}
            </Link>
        </>
    );

    return (
        <div
            className={`bg-ground text-ink flex flex-col ${fullHeight ? 'h-dvh overflow-hidden' : 'min-h-screen'} ${dark ? 'theme-dark' : ''}`}
        >
            <Head>
                <meta
                    head-key="theme-color"
                    name="theme-color"
                    content={chromeColor}
                />
            </Head>
            <header className="border-ink border-b-2">
                <div className="mx-auto flex max-w-7xl items-center justify-between gap-6 px-5 py-4 lg:px-10">
                    <div className="flex items-center gap-10">
                        <Link
                            href="/"
                            className={`font-display flex items-center gap-2 text-3xl font-black uppercase ${dark ? 'text-accent' : 'text-ink'}`}
                        >
                            {t('nav.brand')}
                            {isStaff && (
                                <span className="border-accent text-accent -rotate-3 border-[1.5px] px-1.5 py-0.5 font-mono text-[11px] font-semibold tracking-[0.12em]">
                                    {t('nav.staffTag')}
                                </span>
                            )}
                        </Link>
                        <nav
                            aria-label={t('nav.main')}
                            className="hidden items-center gap-7 lg:flex"
                        >
                            {links}
                        </nav>
                    </div>

                    <div className="hidden items-center gap-6 lg:flex">
                        <OpenStatus park={park} />
                        <LanguageToggle />
                        {account}
                    </div>

                    <button
                        type="button"
                        onClick={() => setMenuOpen((open) => !open)}
                        aria-expanded={menuOpen}
                        aria-controls="mobile-menu"
                        aria-label={
                            menuOpen ? t('nav.closeMenu') : t('nav.openMenu')
                        }
                        className="flex size-11 items-center justify-center lg:hidden"
                    >
                        {menuOpen ? (
                            <CrossIcon size={24} />
                        ) : (
                            <MenuIcon size={24} />
                        )}
                    </button>
                </div>

                {menuOpen && (
                    <div
                        id="mobile-menu"
                        className="border-ink flex flex-col gap-5 border-t-2 px-5 pt-4 pb-6 lg:hidden"
                    >
                        <OpenStatus park={park} />
                        <nav
                            aria-label={t('nav.main')}
                            className="flex flex-col gap-1"
                        >
                            {links}
                        </nav>
                        <div className="border-line flex flex-wrap items-center gap-4 border-t-2 pt-4">
                            {account}
                        </div>
                        <LanguageToggle />
                    </div>
                )}
            </header>

            <main
                className={
                    fullHeight ? 'flex min-h-0 flex-1 flex-col' : 'flex-1'
                }
            >
                {children}
            </main>

            {!fullHeight && (
                <footer className="bg-[#16161a] text-[#f7f6f2]">
                    <div className="mx-auto grid max-w-7xl gap-8 px-5 py-12 sm:grid-cols-3 lg:px-10">
                        <p className="font-display text-5xl font-black uppercase">
                            {t('nav.brand')}
                        </p>
                        <div className="flex flex-col gap-2">
                            <p className="font-mono text-xs font-semibold tracking-[0.12em] text-[#b9b8b2] uppercase">
                                {t('footer.hours')}
                            </p>
                            <p>
                                {t('footer.everyDay', {
                                    opening: park.opening_time,
                                    closing: park.closing_time,
                                })}
                            </p>
                        </div>
                        <div className="flex flex-col gap-2">
                            <p className="font-mono text-xs font-semibold tracking-[0.12em] text-[#b9b8b2] uppercase">
                                {t('footer.park')}
                            </p>
                            <Link
                                href="/livestream"
                                className="hover:text-accent"
                            >
                                {t('nav.livestream')}
                            </Link>
                            {!isStaff && (
                                <>
                                    <Link
                                        href="/subscriptions"
                                        className="hover:text-accent"
                                    >
                                        {t('nav.subscriptions')}
                                    </Link>
                                    <Link
                                        href="/reservations"
                                        className="hover:text-accent"
                                    >
                                        {t('nav.reservations')}
                                    </Link>
                                </>
                            )}
                        </div>
                    </div>
                </footer>
            )}
        </div>
    );
}

/** Standard page width + padding for the customer and staff pages. */
export function PageContainer({
    children,
    className = '',
}: {
    children: ReactNode;
    className?: string;
}) {
    return (
        <div
            className={cn(
                'mx-auto w-full max-w-7xl px-5 py-10 lg:px-10 lg:py-14',
                className,
            )}
        >
            {children}
        </div>
    );
}
