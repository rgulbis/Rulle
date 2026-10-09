<?php

namespace App\Http\Controllers;

use App\Models\SubscriptionType;
use Inertia\Inertia;
use Inertia\Response;

class PassesController extends Controller
{
    /**
     * The public price list: what the passes cost, open to guests and
     * search engines. Buying still happens on the login-only subscriptions
     * page.
     */
    public function index(): Response
    {
        return Inertia::render('passes/index', [
            'plans' => SubscriptionType::forPublicDisplay(),
            'mostPopularPlanId' => SubscriptionType::mostPopularId(),
        ]);
    }
}
