import { Head, router } from '@inertiajs/react';
import { Button, PageHeader, StatusMessage } from '@/components/form-controls';
import PassTicket from '@/components/pass-ticket';
import AppLayout, { PageContainer } from '@/layouts/app-layout';
import { useTranslation } from '@/lib/i18n/context';
import { Plan, useFormatEuros } from '@/lib/plans';

type ActiveSubscription = {
    stripe_status: string;
    ends_at: string | null;
    on_grace_period: boolean;
    canceled: boolean;
};

type ActivePurchase = {
    visits_remaining: number | null;
    subscription_type: { unlimited_entries: boolean };
};

type PriceChange = {
    current_price_cents: number;
    new_price_cents: number;
};

type Props = {
    plans: Plan[];
    mostPopularPlanId: number | null;
    activeSubscription: ActiveSubscription | null;
    activePurchase: ActivePurchase | null;
    priceChange: PriceChange | null;
    status?: string;
};

export default function Subscriptions({
    plans,
    mostPopularPlanId,
    activeSubscription,
    activePurchase,
    priceChange,
    status,
}: Props) {
    const { t, tCount, intlLocale } = useTranslation();
    const formatEuros = useFormatEuros();

    const subscribe = (planId: number) => {
        router.post(`/subscriptions/${planId}/checkout`);
    };

    const cancelSubscription = () => {
        if (confirm(t('subscriptions.confirmCancel'))) {
            router.delete('/subscriptions/subscription');
        }
    };

    const resumeSubscription = () => {
        router.post('/subscriptions/subscription/resume');
    };

    const swapToNewPrice = () => {
        router.post('/subscriptions/subscription/swap');
    };

    const hasActiveSubscription =
        !!activeSubscription && !activeSubscription.canceled;

    // The best seller, worked out server-side from real sales — null until
    // something has sold, in which case no ticket gets the badge.
    const featuredId = mostPopularPlanId;

    const statusMessages: Record<string, string | undefined> = {
        'purchase-incomplete': t('subscriptions.statusPurchaseIncomplete'),
        'purchase-cancelled': t('subscriptions.statusPurchaseCancelled'),
        'subscription-cancelled': t(
            'subscriptions.statusSubscriptionCancelled',
        ),
        'subscription-resumed': t('subscriptions.statusSubscriptionResumed'),
        'price-unchanged': t('subscriptions.statusPriceUnchanged'),
        'pass-already-active': t('subscriptions.statusPassActive'),
    };

    return (
        <AppLayout>
            <Head title={t('nav.subscriptions')} />
            <PageContainer>
                <PageHeader
                    title={t('subscriptions.title')}
                    description={t('home.passesIntro')}
                />

                {status === 'purchase-complete' && (
                    <StatusMessage
                        status={t('subscriptions.statusPurchaseComplete')}
                    />
                )}
                {status === 'price-updated' && (
                    <StatusMessage
                        status={t('subscriptions.statusPriceUpdated')}
                    />
                )}
                {status && statusMessages[status] && (
                    <StatusMessage
                        status={statusMessages[status]}
                        tone="info"
                    />
                )}

                {(activeSubscription || priceChange || activePurchase) && (
                    <div className="mb-12 grid gap-4 md:grid-cols-2">
                        {activeSubscription && (
                            <div className="border-ink bg-paper flex flex-wrap items-center justify-between gap-4 border-2 p-5">
                                {activeSubscription.canceled ? (
                                    <>
                                        <p className="text-muted font-semibold">
                                            {t('subscriptions.cancelledUntil', {
                                                date: activeSubscription.ends_at
                                                    ? new Date(
                                                          activeSubscription.ends_at,
                                                      ).toLocaleDateString(
                                                          intlLocale,
                                                      )
                                                    : t(
                                                          'subscriptions.periodEnd',
                                                      ),
                                            })}
                                        </p>
                                        {activeSubscription.on_grace_period && (
                                            <Button
                                                variant="secondary"
                                                onClick={resumeSubscription}
                                            >
                                                {t(
                                                    'subscriptions.resumeSubscription',
                                                )}
                                            </Button>
                                        )}
                                    </>
                                ) : (
                                    <>
                                        <p className="text-ok font-semibold">
                                            {t('subscriptions.active', {
                                                status: activeSubscription.stripe_status,
                                            })}
                                        </p>
                                        <Button
                                            variant="danger"
                                            onClick={cancelSubscription}
                                        >
                                            {t(
                                                'subscriptions.cancelSubscription',
                                            )}
                                        </Button>
                                    </>
                                )}
                            </div>
                        )}

                        {priceChange && (
                            <div className="border-ink bg-accent text-accent-ink flex flex-wrap items-center justify-between gap-4 border-2 p-5">
                                <p>
                                    {t('subscriptions.priceChanged', {
                                        current: formatEuros(
                                            priceChange.current_price_cents,
                                        ),
                                        new: formatEuros(
                                            priceChange.new_price_cents,
                                        ),
                                    })}
                                </p>
                                <Button
                                    onClick={swapToNewPrice}
                                    className="border-[#16161a] bg-[#16161a] text-[#f7f6f2] shadow-none"
                                >
                                    {t('subscriptions.switchToNewPrice')}
                                </Button>
                            </div>
                        )}

                        {activePurchase && (
                            <div className="border-ink bg-paper border-2 p-5">
                                <p className="text-ok font-semibold">
                                    {activePurchase.subscription_type
                                        .unlimited_entries
                                        ? t('subscriptions.unlimitedToday')
                                        : tCount(
                                              'subscriptions.visitsRemaining',
                                              activePurchase.visits_remaining ??
                                                  0,
                                          )}
                                </p>
                            </div>
                        )}
                    </div>
                )}

                {plans.length === 0 ? (
                    <p className="text-muted text-lg">
                        {t('subscriptions.none')}
                    </p>
                ) : (
                    <div className="grid gap-8 px-2 md:grid-cols-2 xl:grid-cols-3">
                        {plans.map((plan, i) => {
                            const featured = plan.id === featuredId;
                            const hasPass =
                                plan.billing_interval === 'one_time' &&
                                !!activePurchase;
                            const blocked =
                                (plan.billing_interval !== 'one_time' &&
                                    hasActiveSubscription) ||
                                hasPass;

                            return (
                                <PassTicket
                                    key={plan.id}
                                    plan={plan}
                                    index={i}
                                    featured={featured}
                                    action={
                                        blocked ? (
                                            <Button
                                                disabled
                                                variant="secondary"
                                            >
                                                {t(
                                                    hasPass
                                                        ? 'subscriptions.alreadyHavePass'
                                                        : 'subscriptions.alreadySubscribed',
                                                )}
                                            </Button>
                                        ) : (
                                            <Button
                                                variant={
                                                    featured
                                                        ? 'accent'
                                                        : 'secondary'
                                                }
                                                onClick={() =>
                                                    subscribe(plan.id)
                                                }
                                            >
                                                {plan.billing_interval ===
                                                'one_time'
                                                    ? t('subscriptions.buy')
                                                    : t(
                                                          'subscriptions.subscribe',
                                                      )}
                                            </Button>
                                        )
                                    }
                                />
                            );
                        })}
                    </div>
                )}
            </PageContainer>
        </AppLayout>
    );
}
