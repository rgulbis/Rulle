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
    public const INDEXABLE_COMPONENTS = ['welcome', 'livestream/index', 'passes/index', 'groups/index'];

    /**
     * Public paths for the sitemap.
     */
    public const SITEMAP_PATHS = ['/', '/passes', '/groups', '/livestream'];

    public static function isIndexable(string $component): bool
    {
        return in_array($component, self::INDEXABLE_COMPONENTS, true);
    }

    public static function description(?string $component = null, ?string $locale = null): string
    {
        $lv = ($locale ?? app()->getLocale()) === 'lv';

        if ($component === 'livestream/index') {
            return $lv
                ? 'Rullē skeitparka tiešraide Cēsīs - skats uz parku reāllaikā. Netiek ierakstīts vai saglabāts.'
                : 'Live camera from the Rullē skatepark in Cēsis - see the park in real time. Not recorded or saved.';
        }

        if ($component === 'passes/index') {
            return $lv
                ? 'Rullē skeitparka caurlaides un abonementi Cēsīs - cenas, vienreizējas ieejas un mēneša vai gada abonementi. Maksā tiešsaistē, ieeja ar QR kodu.'
                : 'Rullē skatepark passes and memberships in Cēsis - prices, single entries and monthly or yearly memberships. Pay online, enter with a QR code.';
        }

        if ($component === 'groups/index') {
            return $lv
                ? 'Rezervējiet visu Rullē skeitparku Cēsīs savai grupai - dzimšanas dienām, skolas klasēm un komandām. Cena par cilvēku stundā.'
                : 'Book the whole Rullē skatepark in Cēsis for your group - birthdays, school classes and teams. Priced per person per hour.';
        }

        return $lv
            ? 'Rullē - iekštelpu skeitparks Cēsīs (Cesis). Rampas visiem līmeņiem, atvērts katru dienu neatkarīgi no laikapstākļiem. Caurlaides, abonementi, tiešraide.'
            : 'Rullē - an indoor skatepark in Cēsis (Cesis), Latvia. Ramps for every level, open every day whatever the weather. Day passes, memberships, live camera.';
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
            'description' => self::description('welcome', 'en'),
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
