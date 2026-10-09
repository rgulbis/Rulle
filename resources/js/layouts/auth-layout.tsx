import { Head, Link, usePage } from '@inertiajs/react';
import { PropsWithChildren, ReactNode, useEffect } from 'react';
import LanguageToggle from '@/components/language-toggle';
import { useTranslation } from '@/lib/i18n/context';
import type { Park } from '@/types';

export type AuthHeading = {
    lead: string;
    /** The word on the tilted yellow strip. */
    highlight: string;
    tail?: string;
};

export default function AuthLayout({
    title,
    description,
    heading,
    blurb,
    aside,
    children,
}: PropsWithChildren<{
    title: string;
    description?: string;
    heading: AuthHeading;
    blurb?: string;
    /** Decoration pinned to the bottom of the dark panel on wide screens. */
    aside?: ReactNode;
}>) {
    const { t } = useTranslation();
    const { park } = usePage<{ park: Park }>().props;

    // On phones the page opens with the dark panel, so tint the browser's top
    // bar to match it. The light half below paints its own background, so
    // only the top (and the overscroll area) picks the dark colour up.
    useEffect(() => {
        const { documentElement: html, body } = document;
        html.style.backgroundColor = body.style.backgroundColor = '#16161a';

        return () => {
            html.style.backgroundColor = body.style.backgroundColor = '';
        };
    }, []);

    return (
        <>
            <Head title={title}>
                <meta
                    head-key="theme-color"
                    name="theme-color"
                    content="#16161a"
                />
            </Head>
            <div className="bg-ground text-ink grid min-h-dvh lg:h-dvh lg:grid-cols-2">
                {/* Always black regardless of theme - the literal colours are on purpose. */}
                <div className="short:lg:py-7 relative flex flex-col gap-5 overflow-hidden bg-[#16161a] px-5 pt-4 pb-5 text-[#f7f6f2] lg:justify-between lg:gap-8 lg:px-16 lg:py-10">
                    <div className="flex items-center justify-between gap-4">
                        <Link
                            href="/"
                            className="font-display text-accent text-3xl font-black uppercase lg:text-4xl"
                        >
                            {t('nav.brand')}
                        </Link>
                        {/* On phones the toggle rides in this dark header row
                            so the form below gets the whole screen. */}
                        <div className="lg:hidden">
                            <LanguageToggle onDark />
                        </div>
                    </div>

                    <div className="short:gap-4 max-lg:shorter:hidden flex flex-col gap-6">
                        <p
                            aria-hidden="true"
                            // Sized by the window height on desktop, so the
                            // whole panel fits without scrolling.
                            className="font-display text-[2.75rem] leading-none font-black tracking-tight uppercase sm:text-6xl lg:text-[clamp(3.5rem,12.5vh,8rem)]"
                        >
                            {heading.lead}
                            <br />
                            <span className="bg-accent text-accent-ink shadow-hard-paper inline-block -rotate-[2.5deg] px-3 lg:px-4">
                                {heading.highlight}
                            </span>
                            {heading.tail && (
                                <>
                                    <br />
                                    {heading.tail}
                                </>
                            )}
                        </p>
                        {blurb && (
                            <p className="short:lg:hidden hidden max-w-md text-lg leading-relaxed text-[#b9b8b2] lg:block">
                                {blurb}
                            </p>
                        )}
                    </div>

                    <div className="shorter:lg:hidden hidden lg:block">
                        {aside}
                    </div>

                    <div
                        aria-hidden="true"
                        className="animate-slow-spin bg-accent font-display text-accent-ink absolute top-10 right-14 hidden size-32 items-center justify-center rounded-full text-center text-2xl leading-none font-black uppercase xl:flex"
                    >
                        {park.is_open
                            ? t('park.stickerOpen', { time: park.closing_time })
                            : t('park.stickerClosed')}
                    </div>
                </div>

                <div className="short:lg:py-6 relative flex flex-col px-5 py-4 lg:overflow-y-auto lg:px-16 lg:py-10">
                    <div className="absolute top-6 right-10 hidden lg:block">
                        <LanguageToggle />
                    </div>

                    <div className="short:lg:gap-4 mx-auto flex w-full max-w-md flex-1 flex-col justify-center gap-5 lg:gap-6">
                        <div className="flex flex-col gap-2">
                            <h1 className="font-display text-4xl leading-none font-black uppercase sm:text-5xl lg:text-[clamp(2.75rem,7vh,4.5rem)]">
                                {title}
                            </h1>
                            {description && (
                                <p className="text-muted shorter:lg:hidden hidden text-base sm:block lg:text-lg">
                                    {description}
                                </p>
                            )}
                        </div>
                        {children}
                    </div>
                </div>
            </div>
        </>
    );
}

/** Link row under an auth form ("Don't have an account? Sign up"). */
export function AuthFooter({ children }: { children: ReactNode }) {
    return (
        <div className="border-line short:pt-3 flex flex-col gap-2 border-t-2 pt-5 text-base">
            {children}
        </div>
    );
}

/** A tilted, taped-on note for the dark panel. */
export function TapedNote({
    icon,
    eyebrow,
    children,
}: {
    icon: ReactNode;
    eyebrow?: string;
    children: ReactNode;
}) {
    return (
        <div className="relative inline-flex -rotate-3 items-stretch border-2 border-[#f7f6f2] bg-[#f7f6f2] text-[#16161a] transition hover:rotate-0">
            <span
                aria-hidden="true"
                className="bg-accent/90 absolute -top-4 left-10 h-7 w-28 rotate-[4deg]"
            />
            <div className="flex flex-col justify-center gap-1 px-6 py-5">
                {eyebrow && (
                    <p className="font-mono text-xs font-semibold tracking-[0.12em] text-[#45443f] uppercase">
                        {eyebrow}
                    </p>
                )}
                <p className="font-display text-3xl leading-none font-black uppercase">
                    {children}
                </p>
            </div>
            <div className="flex items-center border-l-[3px] border-dashed border-[#16161a] px-5">
                {icon}
            </div>
        </div>
    );
}
