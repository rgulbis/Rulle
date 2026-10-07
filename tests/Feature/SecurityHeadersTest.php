<?php

use App\Models\User;

/**
 * Splits a Content-Security-Policy header into [directive => [sources]].
 *
 * @return array<string, list<string>>
 */
function parseCsp(string $header): array
{
    $directives = [];

    foreach (explode(';', $header) as $part) {
        $tokens = preg_split('/\s+/', trim($part));

        if ($tokens && $tokens[0] !== '') {
            $directives[array_shift($tokens)] = $tokens;
        }
    }

    return $directives;
}

test('responses set X-Frame-Options to block the site being framed by another origin', function () {
    $this->get('/livestream')->assertHeader('X-Frame-Options', 'SAMEORIGIN');
});

test('the admin panel also sets X-Frame-Options', function () {
    $this->get('/admin/login')->assertHeader('X-Frame-Options', 'SAMEORIGIN');
});

test('responses disable MIME sniffing and limit the referrer', function () {
    $this->get('/livestream')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
});

test('the permissions policy only lets this site use the camera', function () {
    $policy = $this->get('/livestream')->headers->get('Permissions-Policy');

    expect($policy)
        ->toContain('camera=(self)')
        ->toContain('microphone=()')
        ->toContain('geolocation=()');
});

test('the app policy only allows scripts from this origin or carrying the nonce', function () {
    $response = $this->get('/livestream');
    $csp = parseCsp($response->headers->get('Content-Security-Policy'));

    expect($csp['default-src'])->toBe(["'self'"])
        ->and($csp['script-src'])->not->toContain("'unsafe-inline'")
        ->and($csp['script-src'])->not->toContain("'unsafe-eval'")
        ->and($csp['object-src'])->toBe(["'none'"])
        ->and($csp['frame-ancestors'])->toBe(["'self'"])
        ->and($csp['base-uri'])->toBe(["'self'"]);

    $nonce = collect($csp['script-src'])
        ->first(fn (string $source) => str_starts_with($source, "'nonce-"));

    expect($nonce)->not->toBeNull();

    // The nonce in the header is the one the page's own script tags carry,
    // otherwise the browser would block the app's own bundle.
    $response->assertSee('nonce="'.substr($nonce, 7, -1).'"', false);
});

test('each request gets its own nonce', function () {
    $nonce = fn () => collect(parseCsp($this->get('/livestream')->headers->get('Content-Security-Policy'))['script-src'])
        ->first(fn (string $source) => str_starts_with($source, "'nonce-"));

    expect($nonce())->not->toBe($nonce());
});

test('the app policy lets the livestream play HLS through hls.js', function () {
    $csp = parseCsp($this->get('/livestream')->headers->get('Content-Security-Policy'));

    // Playlists and segments are fetched same-origin from /live-cam/.
    expect($csp['connect-src'])->toContain("'self'")
        // hls.js feeds them to a MediaSource, which the video plays via a
        // blob: URL, and parses them in a worker created from a blob: URL.
        ->and($csp['media-src'])->toContain("'self'", 'blob:')
        ->and($csp['worker-src'])->toContain("'self'", 'blob:');
});

test('the app policy lets the browser open the Reverb websocket', function () {
    config()->set('security.reverb', ['port' => '443', 'scheme' => 'https']);

    $csp = parseCsp($this->get('/livestream')->headers->get('Content-Security-Policy'));

    expect($csp['connect-src'])->toContain('wss://localhost');
});

test('a non-default Reverb port is spelled out in the policy', function () {
    config()->set('security.reverb', ['port' => '8080', 'scheme' => 'http']);

    $csp = parseCsp($this->get('/livestream')->headers->get('Content-Security-Policy'));

    expect($csp['connect-src'])->toContain('ws://localhost:8080');
});

test('the admin panel gets a looser policy than the app', function () {
    $app = parseCsp($this->get('/livestream')->headers->get('Content-Security-Policy'));
    $admin = parseCsp($this->get('/admin/login')->headers->get('Content-Security-Policy'));

    // Filament's Alpine build and Livewire's inline scripts need these.
    expect($admin['script-src'])->toContain("'unsafe-inline'", "'unsafe-eval'")
        ->and($app['script-src'])->not->toContain("'unsafe-inline'", "'unsafe-eval'")
        // ...but it is still locked to this origin and can't be framed.
        ->and($admin['default-src'])->toBe(["'self'"])
        ->and($admin['frame-ancestors'])->toBe(["'self'"])
        ->and($admin['connect-src'])->toBe(["'self'"]);
});

test('the app policy does not leak the admin relaxations to other pages', function () {
    $admin = User::factory()->create(['role' => 'admin']);

    $csp = parseCsp($this->actingAs($admin)->get('/dashboard')->headers->get('Content-Security-Policy') ?? '');

    expect($csp['script-src'] ?? [])->not->toContain("'unsafe-eval'");
});

test('HSTS is only sent in production over HTTPS', function () {
    $this->get('/livestream')->assertHeaderMissing('Strict-Transport-Security');

    $this->app['env'] = 'production';

    $this->get('https://localhost/livestream')
        ->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');

    $this->get('http://localhost/livestream')->assertHeaderMissing('Strict-Transport-Security');
});
