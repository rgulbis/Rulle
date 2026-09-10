import { Head, router } from '@inertiajs/react';
import { PrimaryButton, StatusMessage } from '@/components/form-controls';
import AppLayout from '@/layouts/app-layout';

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

function formatPrice(cents: number, interval: Plan['billing_interval']) {
    const amount = (cents / 100).toFixed(2) + ' €';

    if (interval === 'month') return `${amount} / month`;
    if (interval === 'year') return `${amount} / year`;

    return amount;
}

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
    const subscribe = (planId: number) => {
        router.post(`/subscriptions/${planId}/checkout`);
    };

    const cancelSubscription = () => {
        if (
            confirm(
                "Cancel your subscription? You'll keep access until the current billing period ends.",
            )
        ) {
            router.delete('/subscriptions/subscription');
        }
    };

    const swapToNewPrice = () => {
        router.post('/subscriptions/subscription/swap');
    };

    const hasActiveSubscription =
        !!activeSubscription && !activeSubscription.canceled;

    return (
        <AppLayout>
            <Head title="Subscriptions" />
            <div className="p-6">
                <div className="mx-auto max-w-3xl">
                    <h1 className="mb-6 text-xl font-semibold text-gray-900">
                        Subscriptions
                    </h1>

                    {status === 'purchase-complete' && (
                        <StatusMessage status="Purchase complete — your access is now active." />
                    )}
                    {status === 'purchase-incomplete' && (
                        <p className="mb-4 rounded-none border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
                            That checkout wasn't completed, so nothing was
                            activated.
                        </p>
                    )}
                    {status === 'purchase-cancelled' && (
                        <p className="mb-4 rounded-none border border-gray-200 bg-gray-50 px-3 py-2 text-sm text-gray-600">
                            Checkout was cancelled.
                        </p>
                    )}
                    {status === 'subscription-cancelled' && (
                        <p className="mb-4 rounded-none border border-gray-200 bg-gray-50 px-3 py-2 text-sm text-gray-600">
                            Your subscription has been cancelled.
                        </p>
                    )}
                    {status === 'price-updated' && (
                        <StatusMessage status="Your subscription now uses the new price." />
                    )}

                    {activeSubscription && (
                        <div className="mb-6 flex items-center justify-between rounded-none border border-gray-200 bg-white p-4 shadow-sm">
                            {activeSubscription.canceled ? (
                                <p className="text-sm font-medium text-gray-600">
                                    Cancelled — access ends{' '}
                                    {activeSubscription.ends_at
                                        ? new Date(
                                              activeSubscription.ends_at,
                                          ).toLocaleDateString()
                                        : 'at period end'}
                                    .
                                </p>
                            ) : (
                                <>
                                    <p className="text-sm font-medium text-green-600">
                                        Active subscription (
                                        {activeSubscription.stripe_status})
                                    </p>
                                    <button
                                        onClick={cancelSubscription}
                                        className="text-sm font-medium text-red-600 hover:text-red-500"
                                    >
                                        Cancel subscription
                                    </button>
                                </>
                            )}
                        </div>
                    )}

                    {priceChange && (
                        <div className="mb-6 flex items-center justify-between rounded-none border border-gray-200 bg-white p-4 shadow-sm">
                            <p className="text-sm text-gray-600">
                                This plan's price has changed: you're on{' '}
                                {formatEuros(priceChange.current_price_cents)},
                                the current price is{' '}
                                {formatEuros(priceChange.new_price_cents)}.
                            </p>
                            <PrimaryButton onClick={swapToNewPrice}>
                                Switch to new price
                            </PrimaryButton>
                        </div>
                    )}

                    {activePurchase && (
                        <div className="mb-6 rounded-none border border-gray-200 bg-white p-4 shadow-sm">
                            <p className="text-sm font-medium text-green-600">
                                {activePurchase.subscription_type
                                    .unlimited_entries
                                    ? 'Unlimited entries today'
                                    : `${activePurchase.visits_remaining} visit(s) remaining`}
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
                                        Unlimited entries, same day
                                    </p>
                                ) : (
                                    plan.visit_limit && (
                                        <p className="text-sm text-gray-500">
                                            {plan.visit_limit} visits
                                        </p>
                                    )
                                )}
                                {plan.billing_interval !== 'one_time' &&
                                hasActiveSubscription ? (
                                    <button
                                        disabled
                                        className="mt-4 cursor-not-allowed rounded-none border border-gray-200 px-4 py-2 text-sm font-medium text-gray-400"
                                    >
                                        Already subscribed
                                    </button>
                                ) : (
                                    <PrimaryButton
                                        className="mt-4"
                                        onClick={() => subscribe(plan.id)}
                                    >
                                        {plan.billing_interval === 'one_time'
                                            ? 'Buy'
                                            : 'Subscribe'}
                                    </PrimaryButton>
                                )}
                            </div>
                        ))}

                        {plans.length === 0 && (
                            <p className="text-sm text-gray-500">
                                No plans are available yet.
                            </p>
                        )}
                    </div>
                </div>
            </div>
        </AppLayout>
    );
}
