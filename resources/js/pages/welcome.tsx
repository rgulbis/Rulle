import { Head, usePage } from '@inertiajs/react';
import { buttonClasses, LinkButton } from '@/components/form-controls';
import { ArrowRightIcon } from '@/components/icons';
import {
    busyLabelKey,
    LiveBadge,
    LiveVideo,
    OccupancyMeter,
    useFormatRanges,
    useOccupancy,
} from '@/components/live';
import PassTicket from '@/components/pass-ticket';
import AppLayout from '@/layouts/app-layout';
import { useTranslation } from '@/lib/i18n/context';
import { Plan, useFormatEuros } from '@/lib/plans';
import type { Park } from '@/types';

type Props = {
    checkedInCount: number;
    todaysReservations: { starts_at: string; ends_at: string }[];
    plans: Plan[];
    mostPopularPlanId: number | null;
    groupBooking: {
        price_cents_per_person_per_hour: number;
        min_group_size: number;
    };
};

export default function Welcome({
    checkedInCount,
    todaysReservations,
    plans,
    mostPopularPlanId,
    groupBooking,
}: Props) {
    const { t } = useTranslation();
    const { park } = usePage<{ park: Park }>().props;
    const count = useOccupancy(checkedInCount);
    const formatRanges = useFormatRanges();
    const formatEuros = useFormatEuros();

    // The best seller, worked out server-side from real sales — null until
    // something has sold, in which case no ticket gets the badge.
    const featuredId = mostPopularPlanId;

    const tickerItems = [
        t('home.ticker.hours', {
            opening: park.opening_time,
            closing: park.closing_time,
        }),
        t('home.ticker.skating', { count }),
        todaysReservations.length > 0
            ? t('home.ticker.reserved', {
                  ranges: formatRanges(todaysReservations),
              })
            : t('home.ticker.noReservations'),
        t('home.ticker.howItWorks'),
    ];

    const steps = [
        [t('home.step1.title'), t('home.step1.text')],
        [t('home.step2.title'), t('home.step2.text')],
        [t('home.step3.title'), t('home.step3.text')],
    ];

    return (
        <AppLayout>
            <Head title={t('home.title')} />

            <div className="overflow-hidden">
                {/* Hero */}
                <section className="mx-auto grid max-w-7xl gap-14 px-5 py-12 lg:grid-cols-12 lg:gap-6 lg:px-10 lg:py-16">
                    <div className="flex flex-col justify-center gap-8 lg:col-span-7">
                        <h1 className="font-display text-7xl leading-none font-black tracking-tight uppercase sm:text-8xl xl:text-[7.75rem]">
                            {t('home.heroLead')}
                            <br />
                            {t('home.heroQueue')}{' '}
                            <span className="bg-accent text-accent-ink shadow-hard inline-block -rotate-[2.5deg] px-3">
                                {t('home.heroHighlight')}
                            </span>
                        </h1>
                        <p className="text-muted max-w-xl text-xl leading-relaxed">
                            {t('home.intro')}
                        </p>
                        <div className="flex flex-wrap gap-5">
                            {/* A plain anchor: an in-page jump, not an Inertia visit. */}
                            <a
                                href="#passes"
                                className={buttonClasses('primary')}
                            >
                                {t('home.seePasses')}
                                <ArrowRightIcon />
                            </a>
                            <LinkButton href="/livestream" variant="secondary">
                                {t('home.watchLive')}
                            </LinkButton>
                        </div>
                    </div>

                    <div data-nosnippet className="relative pt-5 lg:col-span-5">
                        <div className="border-ink shadow-hard-lg relative flex rotate-[1.5deg] flex-col border-2 transition duration-200 hover:rotate-0">
                            <span
                                aria-hidden="true"
                                className="bg-accent/90 absolute -top-5 left-1/2 z-10 h-9 w-36 -translate-x-1/2 -rotate-[4deg]"
                            />
                            <div className="flex items-center gap-3 bg-[#16161a] px-5 py-3.5 text-[#f7f6f2]">
                                <LiveBadge />
                                <h2 className="font-mono text-sm font-semibold tracking-[0.12em] uppercase">
                                    {t('home.parkRightNow')}
                                </h2>
                            </div>
                            <LiveVideo className="aspect-video" />
                            <div className="border-ink bg-paper flex flex-col gap-4 border-t-2 px-6 py-5">
                                <div className="flex flex-wrap items-center justify-between gap-4">
                                    <p className="flex items-baseline gap-3">
                                        <span className="font-display text-6xl leading-none font-black">
                                            {count}
                                        </span>
                                        <span className="text-lg font-semibold">
                                            {t('home.skatingNow')} ·{' '}
                                            {t(busyLabelKey(count))}
                                        </span>
                                    </p>
                                    <LinkButton
                                        href="/livestream"
                                        variant="ghost"
                                        className="px-0"
                                    >
                                        {t('home.camera')}
                                        <ArrowRightIcon />
                                    </LinkButton>
                                </div>
                                <OccupancyMeter count={count} />
                                <p className="text-muted text-sm">
                                    {t('home.countedFromCheckIns')}
                                </p>
                            </div>
                        </div>
                        <div
                            aria-hidden="true"
                            className="animate-slow-spin border-accent font-display text-accent absolute top-20 -right-5 z-20 hidden size-32 items-center justify-center rounded-full border-2 bg-[#16161a] p-3 text-center text-2xl leading-none font-black uppercase xl:-right-10 xl:flex"
                        >
                            {park.is_open
                                ? t('park.stickerOpen', {
                                      time: park.closing_time,
                                  })
                                : t('park.stickerClosed')}
                        </div>
                    </div>
                </section>

                {/* Ticker — decorative repeat of real info, so hidden from
                    screen readers after the first copy. */}
                <div
                    data-nosnippet
                    className="border-accent text-accent relative z-10 -mx-6 my-4 -rotate-[1.5deg] overflow-hidden border-y-[3px] bg-[#16161a]"
                >
                    <div className="animate-ticker font-display flex w-max py-3.5 text-2xl font-extrabold tracking-wide uppercase hover:[animation-play-state:paused] lg:text-3xl">
                        {[0, 1].map((copy) => (
                            <ul
                                key={copy}
                                aria-hidden={copy === 1}
                                className="flex"
                            >
                                {tickerItems.map((item) => (
                                    <li key={item} className="flex">
                                        <span className="px-7">{item}</span>
                                        <span
                                            aria-hidden="true"
                                            className="px-7 text-[#f7f6f2]"
                                        >
                                            /
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        ))}
                    </div>
                </div>

                {/* How it works */}
                <section className="border-ink bg-paper mt-10 border-y-2">
                    <ol className="mx-auto grid max-w-7xl md:grid-cols-3">
                        {steps.map(([title, text], i) => (
                            <li
                                key={title}
                                className="border-ink flex flex-col gap-3 px-5 py-10 not-last:border-b-2 md:not-last:border-r-2 md:not-last:border-b-0 lg:px-10"
                            >
                                <span className="font-mono text-sm font-semibold">
                                    0{i + 1}
                                </span>
                                <h2 className="font-display text-4xl font-extrabold uppercase">
                                    {title}
                                </h2>
                                <p className="text-muted text-lg">{text}</p>
                            </li>
                        ))}
                    </ol>
                </section>

                {/* Passes */}
                <section
                    id="passes"
                    className="mx-auto flex max-w-7xl scroll-mt-6 flex-col gap-12 px-5 py-20 lg:px-10 lg:py-28"
                >
                    <div className="flex flex-wrap items-end justify-between gap-6">
                        <h2 className="font-display text-7xl leading-none font-black uppercase lg:text-8xl">
                            {t('home.passesTitle')}
                        </h2>
                        <p className="text-muted max-w-md text-lg">
                            {t('home.passesIntro')}
                        </p>
                    </div>
                    {plans.length === 0 ? (
                        <p className="text-muted text-lg">
                            {t('subscriptions.none')}
                        </p>
                    ) : (
                        <div className="grid gap-8 px-2 md:grid-cols-2 xl:grid-cols-3">
                            {plans.map((plan, i) => (
                                <PassTicket
                                    key={plan.id}
                                    plan={plan}
                                    index={i}
                                    featured={plan.id === featuredId}
                                    action={
                                        <LinkButton
                                            href="/subscriptions"
                                            variant={
                                                plan.id === featuredId
                                                    ? 'accent'
                                                    : 'secondary'
                                            }
                                        >
                                            {plan.billing_interval ===
                                            'one_time'
                                                ? t('subscriptions.buy')
                                                : t('subscriptions.subscribe')}
                                        </LinkButton>
                                    }
                                />
                            ))}
                        </div>
                    )}
                </section>

                {/* Group bookings */}
                <section className="border-ink bg-accent text-accent-ink border-y-2">
                    <div className="mx-auto grid max-w-7xl gap-12 px-5 py-20 lg:grid-cols-12 lg:gap-6 lg:px-10 lg:py-24">
                        <div className="flex flex-col gap-7 lg:col-span-6">
                            <h2 className="font-display text-7xl leading-none font-black uppercase lg:text-9xl">
                                {t('home.crewLead')}
                                <br />
                                {t('home.crewTail')}
                            </h2>
                            <p className="max-w-md text-xl leading-relaxed">
                                {t('home.crewText', {
                                    price: formatEuros(
                                        groupBooking.price_cents_per_person_per_hour,
                                    ),
                                    min: groupBooking.min_group_size,
                                })}
                            </p>
                            <LinkButton
                                href="/reservations"
                                className="shadow-hard-paper self-start border-[#16161a] bg-[#16161a] text-[#f7f6f2]"
                            >
                                {t('home.bookSlot')}
                            </LinkButton>
                        </div>
                        <div
                            data-nosnippet
                            className="flex rotate-2 flex-col self-start border-2 border-[#16161a] bg-[#f7f6f2] text-[#16161a] shadow-[8px_8px_0_#16161a] transition duration-200 hover:rotate-0 lg:col-span-5 lg:col-start-8"
                        >
                            <div className="flex items-center justify-between gap-4 border-b-2 border-[#16161a] px-6 py-5">
                                <h3 className="text-lg font-semibold">
                                    {t('home.todayTitle')}
                                </h3>
                                <span className="font-mono text-sm text-[#45443f]">
                                    {park.opening_time}–{park.closing_time}
                                </span>
                            </div>
                            {todaysReservations.length === 0 ? (
                                <p className="px-6 py-5 text-base">
                                    {t('home.todayFree')}
                                </p>
                            ) : (
                                <ul className="font-mono text-base">
                                    {todaysReservations.map((r) => (
                                        <li
                                            key={r.starts_at}
                                            className="flex justify-between border-b border-[#d3d0c7] px-6 py-4 last:border-b-0"
                                        >
                                            <span>{formatRanges([r])}</span>
                                            <span className="text-[#45443f]">
                                                {t('home.privatelyBooked')}
                                            </span>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </div>
                    </div>
                </section>
            </div>
        </AppLayout>
    );
}
