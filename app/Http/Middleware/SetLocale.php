<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    /**
     * The languages the frontend's toggle can pick (resources/js/lib/i18n).
     */
    public const SUPPORTED = ['en', 'lv'];

    /**
     * Follows the same `locale` cookie the frontend's language toggle writes,
     * so server-generated text (validation errors, login failures, password
     * reset notices, scan results) comes back in the language the visitor
     * is actually reading the page in. Without the cookie the app's
     * configured locale is left alone.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $locale = $request->cookie('locale');

        if (is_string($locale) && in_array($locale, self::SUPPORTED, true)) {
            app()->setLocale($locale);
        }

        return $next($request);
    }
}
