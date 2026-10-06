<?php

test('responses set X-Frame-Options to block the site being framed by another origin', function () {
    $this->get('/livestream')->assertHeader('X-Frame-Options', 'SAMEORIGIN');
});

test('the admin panel also sets X-Frame-Options', function () {
    $this->get('/admin/login')->assertHeader('X-Frame-Options', 'SAMEORIGIN');
});

test('every response carries the baseline security headers', function () {
    $this->get('/livestream')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
        ->assertHeader('Permissions-Policy', 'camera=(self), microphone=(), geolocation=(), payment=(), usb=()');
});

test('the public site is served with a CSP that allows no inline or eval scripts', function () {
    $csp = $this->get('/livestream')->headers->get('Content-Security-Policy');

    expect($csp)
        ->toContain("default-src 'self'")
        ->toContain("script-src 'self'")
        ->toContain("object-src 'none'")
        ->toContain("frame-ancestors 'self'")
        ->not->toContain("'unsafe-eval'");
    expect(preg_match("/script-src [^;]*'unsafe-inline'/", $csp))->toBe(0);
});

test('only the admin panel may use the inline and eval scripts Livewire and Alpine need', function () {
    $csp = $this->get('/admin/login')->headers->get('Content-Security-Policy');

    expect($csp)->toContain("script-src 'self' 'unsafe-inline' 'unsafe-eval'");
});

test('HSTS is only sent in production over HTTPS', function () {
    $this->get('/livestream')->assertHeaderMissing('Strict-Transport-Security');

    app()->detectEnvironment(fn () => 'production');
    $this->get('https://localhost/livestream')->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
});
