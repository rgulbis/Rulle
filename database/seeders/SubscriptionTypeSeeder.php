<?php

namespace Database\Seeders;

use App\Models\SubscriptionType;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;

/**
 * The plans the park launches with, so a fresh database has something to
 * sell. Matched by English name and only created when missing: running this
 * again (a fresh deploy, a second `db:seed`) never touches a plan that has
 * since been edited, deactivated or sold in the admin panel.
 *
 * Prices are in cents and can be changed in the admin panel afterwards.
 */
class SubscriptionTypeSeeder extends Seeder
{
    public function run(): void
    {
        $plans = [
            [
                'name' => 'Day pass',
                'name_lv' => 'Dienas biļete',
                'description' => 'Unlimited entries on the day you buy it.',
                'description_lv' => 'Neierobežota ieeja pirkuma dienā.',
                'price_cents' => 800,
                'billing_interval' => 'one_time',
                'visit_limit' => null,
                'unlimited_entries' => true,
            ],
            [
                'name' => 'Monthly pass',
                'name_lv' => 'Mēneša abonements',
                'description' => 'Unlimited visits, renews every month until you cancel.',
                'description_lv' => 'Neierobežoti apmeklējumi, atjaunojas ik mēnesi, līdz atceļat.',
                'price_cents' => 3500,
                'billing_interval' => 'month',
                'visit_limit' => null,
                'unlimited_entries' => false,
            ],
            [
                'name' => 'Yearly pass',
                'name_lv' => 'Gada abonements',
                'description' => 'Unlimited visits, renews every year until you cancel.',
                'description_lv' => 'Neierobežoti apmeklējumi, atjaunojas ik gadu, līdz atceļat.',
                'price_cents' => 30000,
                'billing_interval' => 'year',
                'visit_limit' => null,
                'unlimited_entries' => false,
            ],
        ];

        foreach ($plans as $plan) {
            SubscriptionType::firstOrCreate(['name' => $plan['name']], $plan + ['active' => true]);
        }

        // Plans only become purchasable once they have a Stripe Product and
        // Price. Not fatal if that can't happen right now (no keys locally,
        // Stripe down): `php artisan plans:sync-stripe` does it later, and
        // the admin panel flags any plan that is still out of step.
        if (filled(config('cashier.secret'))) {
            Artisan::call('plans:sync-stripe');
        }
    }
}
