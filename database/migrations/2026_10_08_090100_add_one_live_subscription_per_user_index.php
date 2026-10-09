<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Cashier's `subscriptions` table has no unique (user_id, type): a plain
     * one would stop anyone from ever re-subscribing, because the old,
     * cancelled row stays. What must never exist is two *live* rows for one
     * customer, and a partial index says exactly that. "Live" mirrors the
     * checkout rule - no end date (a cancelled-but-still-running
     * subscription has one, so re-subscribing is allowed) and a status that
     * is still being billed.
     *
     * It is the database backstop; the checkout lock and the webhook check
     * keep the application from ever running into it.
     */
    public function up(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            return;
        }

        $duplicates = DB::select(<<<'SQL'
            SELECT user_id, type, COUNT(*) AS live
            FROM subscriptions
            WHERE ends_at IS NULL AND stripe_status IN ('active', 'trialing', 'past_due')
            GROUP BY user_id, type
            HAVING COUNT(*) > 1
        SQL);

        if ($duplicates !== []) {
            $who = collect($duplicates)->map(fn ($row) => "user {$row->user_id} ({$row->type}: {$row->live})")->implode(', ');

            throw new RuntimeException("Cannot add the one-live-subscription index: these customers already have several live subscriptions - cancel the extras at Stripe and in the subscriptions table first: {$who}");
        }

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX subscriptions_one_live_per_user_type
            ON subscriptions (user_id, type)
            WHERE ends_at IS NULL AND stripe_status IN ('active', 'trialing', 'past_due')
        SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS subscriptions_one_live_per_user_type');
    }
};
