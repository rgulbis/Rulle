<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" @class(['dark' => ($appearance ?? 'system') == 'dark'])>
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        @php
            $component = $page['component'] ?? '';
            $indexable = \App\Support\Seo::isIndexable($component);
            $locale = app()->getLocale();
            $description = \App\Support\Seo::description($component);
            $canonical = \App\Support\Seo::absoluteUrl(request()->getPathInfo());
            $shareImage = \App\Support\Seo::absoluteUrl('/og-image.png');
        @endphp
        <meta name="description" content="{{ $description }}">
        <meta name="robots" content="{{ $indexable ? 'index, follow' : 'noindex, nofollow' }}">
        <link rel="canonical" href="{{ $canonical }}">

        <meta property="og:type" content="website">
        <meta property="og:site_name" content="{{ config('app.name') }}">
        <meta property="og:title" content="{{ config('app.name') }}">
        <meta property="og:description" content="{{ $description }}">
        <meta property="og:url" content="{{ $canonical }}">
        <meta property="og:image" content="{{ $shareImage }}">
        <meta property="og:image:width" content="1200">
        <meta property="og:image:height" content="630">
        <meta property="og:locale" content="{{ $locale === 'lv' ? 'lv_LV' : 'en_US' }}">
        <meta property="og:locale:alternate" content="{{ $locale === 'lv' ? 'en_US' : 'lv_LV' }}">
        <meta name="twitter:card" content="summary_large_image">
        <meta name="twitter:title" content="{{ config('app.name') }}">
        <meta name="twitter:description" content="{{ $description }}">
        <meta name="twitter:image" content="{{ $shareImage }}">

        @if ($component === 'welcome')
            <script type="application/ld+json">{!! json_encode(\App\Support\Seo::structuredData(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) !!}</script>
        @endif

        {{-- Google's favicon crawler wants a raster icon whose size is a multiple of 48px, at a stable URL. --}}
        <link rel="icon" href="/favicon.ico" sizes="48x48">
        <link rel="icon" href="/favicon-192.png" type="image/png" sizes="192x192">
        <link rel="icon" href="/favicon.svg" type="image/svg+xml" sizes="any">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png">

        @fonts

        @viteReactRefresh
        @vite(['resources/css/app.css', 'resources/js/app.tsx', "resources/js/pages/{$page['component']}.tsx"])
        <x-inertia::head>
            <title>{{ config('app.name', 'Laravel') }}</title>
        </x-inertia::head>
    </head>
    <body class="font-sans antialiased">
        <x-inertia::app />
    </body>
</html>
