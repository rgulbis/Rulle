<?php

namespace App\Http\Middleware;

use App\Models\ReservationSetting;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                // checked_in isn't a column on `users` (see
                // User::isCurrentlyCheckedIn()) — added back here explicitly
                // rather than via the model's global $appends, so it's only
                // ever computed for the one logged-in user on every page
                // load, not for every User serialized anywhere else in the
                // app (reservation participants, chat senders, Filament...).
                'user' => $user ? [
                    ...$user->toArray(),
                    'checked_in' => $user->isCurrentlyCheckedIn(),
                ] : null,
            ],
            // The language picked with the frontend's toggle (its cookie),
            // so a server-side render comes out in the same language the
            // browser then hydrates it in. Null = not chosen yet; the
            // frontend falls back to its own default.
            'locale' => in_array($request->cookie('locale'), SetLocale::SUPPORTED, true)
                ? $request->cookie('locale')
                : null,
            // Opening hours for the nav's "open until…" badge on every page.
            // Whether it's open right now is decided here, in the app's
            // timezone, rather than by the visitor's own clock.
            'park' => function () {
                $settings = ReservationSetting::current();
                $minutes = now()->hour * 60 + now()->minute;

                return [
                    'opening_time' => $settings->opening_time,
                    'closing_time' => $settings->closing_time,
                    'is_open' => $minutes >= $settings->openingMinutes()
                        && $minutes < $settings->closingMinutes(),
                ];
            },
        ];
    }
}
