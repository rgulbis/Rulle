<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * A read-only view, not a table - nothing ever inserts into `payments`
     * directly. Money is recorded in three genuinely separate places
     * (one-time passes, reservations, and Stripe-synced subscriptions), and
     * the admin "Payments" list needs to show all three as one ledger
     * without duplicating that data into a fourth table to keep in sync.
     *
     * The subscription branch resolves its plan (and so its price) by
     * joining subscription_items -> subscription_types on Stripe *product*
     * id, the same join SubscriptionType::mostPopularId() already uses -
     * Stripe *prices* are immutable and get replaced whenever an admin
     * edits a plan, but the product id stays fixed for that plan's
     * lifetime. This assumes one item per subscription, true for every
     * subscription this app creates (SubscriptionController always calls
     * newSubscription() with a single price).
     */
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE VIEW payments AS
            SELECT
                'purchase-' || purchases.id AS id,
                'purchase' AS type,
                purchases.user_id AS user_id,
                subscription_types.name AS description,
                purchases.price_cents AS amount_cents,
                purchases.status AS status,
                purchases.created_at AS created_at
            FROM purchases
            LEFT JOIN subscription_types ON subscription_types.id = purchases.subscription_type_id

            UNION ALL

            SELECT
                'reservation-' || reservations.id AS id,
                'reservation' AS type,
                reservations.user_id AS user_id,
                'Park reservation' AS description,
                reservations.price_cents AS amount_cents,
                reservations.status AS status,
                reservations.created_at AS created_at
            FROM reservations

            UNION ALL

            SELECT
                'subscription-' || subscriptions.id AS id,
                'subscription' AS type,
                subscriptions.user_id AS user_id,
                COALESCE(subscription_types.name, 'Subscription') AS description,
                COALESCE(subscription_types.price_cents, 0) AS amount_cents,
                subscriptions.stripe_status AS status,
                subscriptions.created_at AS created_at
            FROM subscriptions
            LEFT JOIN subscription_items ON subscription_items.subscription_id = subscriptions.id
            LEFT JOIN subscription_types ON subscription_types.stripe_product_id = subscription_items.stripe_product
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS payments');
    }
};
