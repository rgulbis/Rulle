import { Head } from '@inertiajs/react';
import { LinkButton } from '@/components/form-controls';
import PassTicket from '@/components/pass-ticket';
import AppLayout, { PageContainer } from '@/layouts/app-layout';
import { useTranslation } from '@/lib/i18n/context';
import type { Plan } from '@/lib/plans';

type Props = {
    plans: Plan[];
    mostPopularPlanId: number | null;
};

export default function Passes({ plans, mostPopularPlanId }: Props) {
    const { t } = useTranslation();

    return (
        <AppLayout>
            <Head title={t('passes.title')} />

            <PageContainer className="flex flex-col gap-12">
                <div className="flex flex-wrap items-end justify-between gap-6">
                    <h1 className="font-display text-6xl leading-none font-black uppercase lg:text-8xl">
                        {t('passes.title')}
                    </h1>
                    <p className="text-muted max-w-md text-lg">
                        {t('home.passesIntro')}
                    </p>
                </div>

                {plans.length === 0 ? (
                    <p className="text-muted text-lg">
                        {t('subscriptions.none')}
                    </p>
                ) : (
                    <div
                        data-nosnippet
                        className="grid gap-8 px-2 md:grid-cols-2 xl:grid-cols-3"
                    >
                        {plans.map((plan, i) => (
                            <PassTicket
                                key={plan.id}
                                plan={plan}
                                index={i}
                                featured={plan.id === mostPopularPlanId}
                                action={
                                    <LinkButton
                                        href="/subscriptions"
                                        variant={
                                            plan.id === mostPopularPlanId
                                                ? 'accent'
                                                : 'secondary'
                                        }
                                    >
                                        {plan.billing_interval === 'one_time'
                                            ? t('subscriptions.buy')
                                            : t('subscriptions.subscribe')}
                                    </LinkButton>
                                }
                            />
                        ))}
                    </div>
                )}
            </PageContainer>
        </AppLayout>
    );
}
