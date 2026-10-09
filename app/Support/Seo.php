<?php

namespace App\Support;

use App\Models\ReservationSetting;

/**
 * What search engines and link previews are told about the site: which pages
 * may be indexed, the page description, and the structured data Google uses
 * for the park's "knowledge panel" (name, place, opening hours).
 */
class Seo
{
    /**
     * Inertia components of the public pages worth finding in a search. All
     * the rest (account, chat, payments, staff, auth) are private or useless
     * as a search result and are marked noindex.
     */
    public const INDEXABLE_COMPONENTS = ['welcome', 'livestream/index'];

    /**
     * Public paths for the sitemap.
     */
    public const SITEMAP_PATHS = ['/', '/livestream'];

    public static function isIndexable(string $component): bool
    {
        return in_array($component, self::INDEXABLE_COMPONENTS, true);
    }

    public static function description(?string $locale = null): string
    {
        return ($locale ?? app()->getLocale()) === 'lv'
            ? 'Rullē (Rulle) - iekštelpu skeitparks Cēsīs (Cesis): caurlaides un abonementi, grupu rezervācijas, tiešraide no parka un ieeja ar QR kodu. Atvērts katru dienu.'
            : 'Rullē (Rulle) - an indoor skatepark in Cēsis (Cesis), Latvia: day passes and memberships, group bookings, a live camera and QR code entry. Open every day.';
    }

    public static function absoluteUrl(string $path = '/'): string
    {
        return rtrim((string) config('app.url'), '/').'/'.ltrim($path, '/');
    }

    /**
     * schema.org markup for the landing page.
     *
     * @return array<string, mixed>
     */
    public static function structuredData(): array
    {
        $settings = ReservationSetting::current();

        return [
            '@context' => 'https://schema.org',
            '@type' => 'SportsActivityLocation',
            'name' => config('app.name'),
            // People type the name without diacritics (and in either
            // language's word for the place), so say those spellings out loud.
            'alternateName' => ['Rulle', 'Rulle skeitparks', 'Rulle skatepark', 'Rullē skeitparks'],
            'url' => self::absoluteUrl('/'),
            'image' => self::absoluteUrl('/og-image.png'),
            'description' => self::description('en'),
            'sport' => 'Skateboarding',
            'address' => [
                '@type' => 'PostalAddress',
                'addressLocality' => 'Cēsis',
                'addressCountry' => 'LV',
            ],
            'openingHoursSpecification' => [[
                '@type' => 'OpeningHoursSpecification',
                'dayOfWeek' => ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'],
                'opens' => $settings->opening_time,
                'closes' => $settings->closing_time,
            ]],
        ];
    }
}
