import { Head, usePage } from '@inertiajs/react';
import { Eyebrow, LinkButton } from '@/components/form-controls';
import {
    busyLabelKey,
    LiveBadge,
    LiveVideo,
    OccupancyMeter,
    useFormatRanges,
    useOccupancy,
} from '@/components/live';
import AppLayout, { PageContainer } from '@/layouts/app-layout';
import { useTranslation } from '@/lib/i18n/context';
import type { Park } from '@/types';
import type { User } from '@/types/auth';

type TimeRange = {
    starts_at: string;
    ends_at: string;
};

type Props = {
    checkedInCount: number;
    todaysReservations: TimeRange[];
};

export default function Livestream({
    checkedInCount,
    todaysReservations,
}: Props) {
    const { t, tCount } = useTranslation();
    const { auth, park } = usePage<{
        auth: { user: User | null };
        park: Park;
    }>().props;
    const count = useOccupancy(checkedInCount);
    const formatRanges = useFormatRanges();

    return (
        <AppLayout theme="dark">
            <Head title={t('livestream.title')} />
            <PageContainer className="grid gap-8 lg:grid-cols-12">
                <div className="flex flex-col gap-4 lg:col-span-9">
                    <div className="border-line relative border-2">
                        <LiveVideo className="aspect-video" />
                        <div className="pointer-events-none absolute top-4 left-4 flex gap-3">
                            <LiveBadge />
                        </div>
                    </div>
                    <div className="flex flex-col gap-1">
                        <h1 className="font-display text-5xl font-black uppercase lg:text-6xl">
                            {t('livestream.title')}
                        </h1>
                        <p className="text-muted text-base">
                            {t('livestream.subtitle')}
                        </p>
                    </div>
                </div>

                <aside className="flex flex-col gap-6 lg:col-span-3">
                    <section className="flex flex-col gap-3 bg-[#f7f6f2] p-7 text-[#16161a]">
                        <Eyebrow className="text-[#45443f]">
                            {t('livestream.inParkNow')}
                        </Eyebrow>
                        <p className="font-display text-8xl leading-none font-black">
                            {count}
                        </p>
                        <p className="text-lg font-semibold">
                            {tCount('livestream.checkedInCount', count)} ·{' '}
                            {t(busyLabelKey(count))}
                        </p>
                        <OccupancyMeter
                            count={count}
                            filled="bg-[#16161a]"
                            empty="bg-[#d3d0c7]"
                        />
                    </section>

                    <section className="border-line flex flex-col gap-2 border-2 p-7">
                        <Eyebrow className="text-muted">
                            {t('livestream.today')}
                        </Eyebrow>
                        <p className="text-lg font-semibold">
                            {t('livestream.openHours', {
                                opening: park.opening_time,
                                closing: park.closing_time,
                            })}
                        </p>
                        <p className="text-muted text-base">
                            {todaysReservations.length === 0
                                ? t('livestream.noReservationsToday')
                                : t('livestream.reservedToday', {
                                      ranges: formatRanges(todaysReservations),
                                  })}
                        </p>
                    </section>

                    {!auth.user && (
                        <section className="bg-accent text-accent-ink mt-auto flex flex-col gap-4 p-7">
                            <p className="font-display text-4xl leading-none font-black uppercase">
                                {t('livestream.cta')}
                            </p>
                            <LinkButton
                                href="/register"
                                className="border-[#16161a] bg-[#16161a] text-[#f7f6f2] shadow-none"
                            >
                                {t('nav.signUp')}
                            </LinkButton>
                        </section>
                    )}
                </aside>
            </PageContainer>
        </AppLayout>
    );
}
