import { Head, router } from '@inertiajs/react';
import { PrimaryButton, StatusMessage } from '@/components/form-controls';
import AppLayout from '@/layouts/app-layout';
import { useTranslation } from '@/lib/i18n/context';

type Plan = {
    id: number;
    name: string;
    description: string | null;
    price_cents: number;
    billing_interval: 'one_time' | 'month' | 'year';
    visit_limit: number | null;
    unlimited_entries: boolean;
};

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
    activeSubscription: ActiveSubscription | null;
    activePurchase: ActivePurchase | null;
    priceChange: PriceChange | null;
    status?: string;
};

function formatEuros(cents: number) {
    return (cents / 100).toFixed(2) + ' €';
}

export default function Subscriptions({
    plans,
    activeSubscription,
    activePurchase,
    priceChange,
    status,
}: Props) {
    const { t, intlLocale } = useTranslation();

    const formatPrice = (cents: number, interval: Plan['billing_interval']) => {
        const amount = formatEuros(cents);

        if (interval === 'month')
            return t('subscriptions.perMonth', { amount });
        if (interval === 'year') return t('subscriptions.perYear', { amount });

        return amount;
    };

    const subscribe = (planId: number) => {
        router.post(`/subscriptions/${planId}/checkout`);
    };

    const cancelSubscription = () => {
        if (confirm(t('subscriptions.confirmCancel'))) {
            router.delete('/subscriptions/subscription');
        }
    };

    const swapToNewPrice = () => {
        router.post('/subscriptions/subscription/swap');
    };

    const hasActiveSubscription =
        !!activeSubscription && !activeSubscription.canceled;

    const statusMessages: Record<string, string | undefined> = {
        'purchase-incomplete': t('subscriptions.statusPurchaseIncomplete'),
        'purchase-cancelled': t('subscriptions.statusPurchaseCancelled'),
        'subscription-cancelled': t(
            'subscriptions.statusSubscriptionCancelled',
        ),
    };

    return (
        <AppLayout>
            <Head title={t('nav.subscriptions')} />
            <div className="p-6">
                <div className="mx-auto max-w-3xl">
                    <h1 className="mb-6 text-xl font-semibold text-gray-900">
                        {t('subscriptions.title')}
                    </h1>

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
                        <p className="mb-4 rounded-none border border-gray-200 bg-gray-50 px-3 py-2 text-sm text-gray-600">
                            {statusMessages[status]}
                        </p>
                    )}

                    {activeSubscription && (
                        <div className="mb-6 flex items-center justify-between rounded-none border border-gray-200 bg-white p-4 shadow-sm">
                            {activeSubscription.canceled ? (
                                <p className="text-sm font-medium text-gray-600">
                                    {t('subscriptions.cancelledUntil', {
                                        date: activeSubscription.ends_at
                                            ? new Date(
                                                  activeSubscription.ends_at,
                                              ).toLocaleDateString(intlLocale)
                                            : t('subscriptions.periodEnd'),
                                    })}
                                </p>
                            ) : (
                                <>
                                    <p className="text-sm font-medium text-green-600">
                                        {t('subscriptions.active', {
                                            status: activeSubscription.stripe_status,
                                        })}
                                    </p>
                                    <button
                                        onClick={cancelSubscription}
                                        className="text-sm font-medium text-red-600 hover:text-red-500"
                                    >
                                        {t('subscriptions.cancelSubscription')}
                                    </button>
                                </>
                            )}
                        </div>
                    )}

                    {priceChange && (
                        <div className="mb-6 flex items-center justify-between rounded-none border border-gray-200 bg-white p-4 shadow-sm">
                            <p className="text-sm text-gray-600">
                                {t('subscriptions.priceChanged', {
                                    current: formatEuros(
                                        priceChange.current_price_cents,
                                    ),
                                    new: formatEuros(
                                        priceChange.new_price_cents,
                                    ),
                                })}
                            </p>
                            <PrimaryButton onClick={swapToNewPrice}>
                                {t('subscriptions.switchToNewPrice')}
                            </PrimaryButton>
                        </div>
                    )}

                    {activePurchase && (
                        <div className="mb-6 rounded-none border border-gray-200 bg-white p-4 shadow-sm">
                            <p className="text-sm font-medium text-green-600">
                                {activePurchase.subscription_type
                                    .unlimited_entries
                                    ? t('subscriptions.unlimitedToday')
                                    : t('subscriptions.visitsRemaining', {
                                          count:
                                              activePurchase.visits_remaining ??
                                              0,
                                      })}
                            </p>
                        </div>
                    )}

                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        {plans.map((plan) => (
                            <div
                                key={plan.id}
                                className="flex flex-col rounded-none border border-gray-200 bg-white p-6 shadow-sm"
                            >
                                <h2 className="text-base font-semibold text-gray-900">
                                    {plan.name}
                                </h2>
                                {plan.description && (
                                    <p className="mt-1 text-sm text-gray-500">
                                        {plan.description}
                                    </p>
                                )}
                                <p className="mt-4 text-lg font-semibold text-gray-900">
                                    {formatPrice(
                                        plan.price_cents,
                                        plan.billing_interval,
                                    )}
                                </p>
                                {plan.unlimited_entries ? (
                                    <p className="text-sm text-gray-500">
                                        {t('subscriptions.unlimitedSameDay')}
                                    </p>
                                ) : (
                                    plan.visit_limit && (
                                        <p className="text-sm text-gray-500">
                                            {t('subscriptions.visitsCount', {
                                                count: plan.visit_limit,
                                            })}
                                        </p>
                                    )
                                )}
                                {plan.billing_interval !== 'one_time' &&
                                hasActiveSubscription ? (
                                    <button
                                        disabled
                                        className="mt-4 cursor-not-allowed rounded-none border border-gray-200 px-4 py-2 text-sm font-medium text-gray-400"
                                    >
                                        {t('subscriptions.alreadySubscribed')}
                                    </button>
                                ) : (
                                    <PrimaryButton
                                        className="mt-4"
                                        onClick={() => subscribe(plan.id)}
                                    >
                                        {plan.billing_interval === 'one_time'
                                            ? t('subscriptions.buy')
                                            : t('subscriptions.subscribe')}
                                    </PrimaryButton>
                                )}
                            </div>
                        ))}

                        {plans.length === 0 && (
                            <p className="text-sm text-gray-500">
                                {t('subscriptions.none')}
                            </p>
                        )}
                    </div>
                </div>
            </div>
        </AppLayout>
    );
}
