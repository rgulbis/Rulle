<?php

test('the home page can be indexed and describes the park', function () {
    $html = $this->get('/')->assertOk()->getContent();

    expect($html)
        ->toContain('<meta name="robots" content="index, follow">')
        ->toContain('<link rel="canonical" href="'.rtrim(config('app.url'), '/').'/">')
        ->toContain('<meta property="og:image" content="'.rtrim(config('app.url'), '/').'/og-image.png">')
        ->toContain('<meta name="twitter:card" content="summary_large_image">')
        ->toContain('name="description"')
        ->toContain('<script type="application/ld+json">')
        ->toContain('"@type":"SportsActivityLocation"')
        ->toContain('"opens":"08:00"')
        ->toContain('"alternateName":["Rulle","Rulle skeitparks"');
});

test('the livestream page can be indexed but has no structured data block', function () {
    $html = $this->get('/livestream')->assertOk()->getContent();

    expect($html)
        ->toContain('<meta name="robots" content="index, follow">')
        ->not->toContain('application/ld+json');
});

test('the passes and groups pages are public, indexable and have their own description', function () {
    makeSubscriptionType(['name' => 'Day pass', 'price_cents' => 500]);

    $passes = $this->get('/passes')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('passes/index')->has('plans', 1)->missing('plans.0.stripe_price_id'))
        ->getContent();
    $groups = $this->get('/groups')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('groups/index')->has('groupBooking.min_group_size'))
        ->getContent();

    expect($passes)
        ->toContain('<meta name="robots" content="index, follow">')
        ->toContain('passes and memberships in')
        ->not->toContain('application/ld+json')
        ->and($groups)
        ->toContain('<meta name="robots" content="index, follow">')
        ->toContain('for your group');
});

test('private and sign-in pages are marked noindex', function () {
    expect($this->get('/login')->getContent())
        ->toContain('<meta name="robots" content="noindex, nofollow">');
});

test('the share image exists and is the size the tags promise', function () {
    [$width, $height] = getimagesize(public_path('og-image.png'));

    expect([$width, $height])->toBe([1200, 630]);
});

test('robots.txt keeps crawlers out unless this is production', function () {
    $this->get('/robots.txt')
        ->assertOk()
        ->assertHeader('Content-Type', 'text/plain; charset=UTF-8')
        ->assertSee('Disallow: /', false)
        ->assertDontSee('Sitemap:');
});

test('robots.txt in production allows the public pages and points at the sitemap', function () {
    app()->detectEnvironment(fn () => 'production');

    $body = $this->get('/robots.txt')->assertOk()->getContent();

    expect($body)
        ->toContain('Disallow: /admin')
        ->toContain('Disallow: /dashboard')
        ->toContain('Sitemap: '.rtrim(config('app.url'), '/').'/sitemap.xml')
        ->not->toContain("Disallow: /\n");
});

test('the sitemap lists the public pages only', function () {
    $body = $this->get('/sitemap.xml')
        ->assertOk()
        ->assertHeader('Content-Type', 'application/xml; charset=UTF-8')
        ->getContent();

    $base = rtrim(config('app.url'), '/');

    expect($body)
        ->toContain("<loc>{$base}/</loc>")
        ->toContain("<loc>{$base}/livestream</loc>")
        ->toContain("<loc>{$base}/passes</loc>")
        ->toContain("<loc>{$base}/groups</loc>")
        ->not->toContain('/dashboard')
        ->not->toContain('/admin');
});
