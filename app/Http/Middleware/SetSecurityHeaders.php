<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

class SetSecurityHeaders
{
    /**
     * This middleware is registered on both the web group and the Filament
     * panel, so a request could pass through it twice. The nonce must be
     * generated exactly once per request (the view is rendered with the first
     * one), so the second pass is a no-op.
     */
    private const APPLIED = 'security-headers-applied';

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->attributes->get(self::APPLIED)) {
            return $next($request);
        }

        $request->attributes->set(self::APPLIED, true);

        $admin = $request->is('admin', 'admin/*');

        // The app's own pages get a nonce so inline <script> tags emitted by
        // Vite (the React refresh preamble in dev) and Laravel Boost's
        // browser logger can run without allowing 'unsafe-inline' scripts.
        // It has to be set before the view renders.
        $nonce = $admin ? null : Vite::useCspNonce();

        $response = $next($request);

        // Blocks the site from being framed by another origin. Without this, a
        // logged-in visitor could be tricked into clicking through an
        // invisible iframe of a real page here (e.g. "cancel subscription",
        // "delete message") layered under attacker-controlled content
        // elsewhere. The CSP's frame-ancestors says the same for browsers
        // that support it; this header covers the rest.
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');

        // The staff scanner is the only thing that needs the camera.
        $response->headers->set(
            'Permissions-Policy',
            'camera=(self), microphone=(), geolocation=(), payment=(), usb=(), interest-cohort=()',
        );

        $response->headers->set(
            'Content-Security-Policy',
            $admin ? $this->adminPolicy() : $this->appPolicy($request, $nonce),
        );

        // Only over HTTPS (Cloudflare terminates TLS and the proxy headers
        // are trusted, see bootstrap/app.php): a browser ignores HSTS sent
        // over plain HTTP, and local dev has no certificate to enforce it on.
        if (app()->isProduction() && $request->isSecure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }

    /**
     * Policy for the customer/staff app (Inertia + React).
     *
     * Scripts are nonce-only. Styles allow 'unsafe-inline' because the
     * `@fonts` directive emits an inline <style> block with no nonce support
     * and UI primitives set style attributes; that is a much smaller risk
     * than inline scripts.
     *
     * Two pieces are needed by features that are easy to break later:
     *  - Reverb (chat, live headcount): a WebSocket to this same host, on the
     *    port the browser is built with (see resources/js/echo.ts).
     *  - The livestream (HLS): playlists and segments are fetched same-origin
     *    from /live-cam/, and hls.js plays them through a MediaSource
     *    (`blob:` in media-src) and parses them in a blob-URL Web Worker
     *    (`blob:` in worker-src).
     */
    private function appPolicy(Request $request, ?string $nonce): string
    {
        $dev = $this->devServer();

        $directives = [
            'default-src' => ["'self'"],
            'script-src' => ["'self'", "'nonce-{$nonce}'", ...$dev],
            'style-src' => ["'self'", "'unsafe-inline'", ...$dev],
            // data: for generated QR codes, blob: for the scanner's frames.
            'img-src' => ["'self'", 'data:', 'blob:', ...$dev],
            'font-src' => ["'self'", 'data:', ...$dev],
            'media-src' => ["'self'", 'blob:'],
            'worker-src' => ["'self'", 'blob:'],
            'connect-src' => ["'self'", ...$this->reverbSources($request), ...$dev, ...$this->devSocket()],
            'object-src' => ["'none'"],
            'base-uri' => ["'self'"],
            'frame-ancestors' => ["'self'"],
        ];

        return $this->compile($directives);
    }

    /**
     * Policy for the Filament panel. Looser on purpose: Filament's Alpine
     * build evaluates expressions at runtime ('unsafe-eval') and Livewire
     * and Filament write inline scripts and styles into every page, none of
     * which can carry our nonce. The panel is only reachable by logged-in
     * staff, and everything else on it is still locked to this origin.
     */
    private function adminPolicy(): string
    {
        $directives = [
            'default-src' => ["'self'"],
            'script-src' => ["'self'", "'unsafe-inline'", "'unsafe-eval'"],
            'style-src' => ["'self'", "'unsafe-inline'"],
            // Filament's default avatar for users without a photo.
            'img-src' => ["'self'", 'data:', 'blob:', 'https://ui-avatars.com'],
            'font-src' => ["'self'", 'data:'],
            'connect-src' => ["'self'"],
            'object-src' => ["'none'"],
            'base-uri' => ["'self'"],
            'frame-ancestors' => ["'self'"],
        ];

        return $this->compile($directives);
    }

    /**
     * `'self'` should already cover a same-host WebSocket, but older Safari
     * versions don't treat it that way, and when Reverb is on a non-default
     * port (local dev) the port has to be spelled out anyway.
     *
     * @return list<string>
     */
    private function reverbSources(Request $request): array
    {
        $secure = config('security.reverb.scheme') === 'https';
        $port = config('security.reverb.port');
        $default = $secure ? 443 : 80;

        $source = ($secure ? 'wss' : 'ws').'://'.$request->getHost();

        if ($port && (int) $port !== $default) {
            $source .= ':'.$port;
        }

        return [$source];
    }

    /**
     * The Vite dev server's origin (with `npm run dev` running), so assets
     * and HMR work with the policy on. Nothing in production, where assets
     * are built into public/build and served from this origin.
     *
     * Vite often listens on an IPv6 literal (`http://[::1]:5173`), which CSP
     * host-sources can't express — browsers never match it — so for those
     * the whole scheme is allowed instead. That only ever applies on a
     * machine with a public/hot file.
     *
     * @return list<string>
     */
    private function devServer(): array
    {
        $parts = $this->devServerUrl();

        if ($parts === null) {
            return [];
        }

        if (str_starts_with($parts['host'], '[')) {
            return [$parts['scheme'].':'];
        }

        return [$parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '')];
    }

    /**
     * The dev server's WebSocket (Vite's HMR) sources.
     *
     * @return list<string>
     */
    private function devSocket(): array
    {
        return array_map(
            fn (string $source) => preg_replace('/^http/', 'ws', $source),
            $this->devServer(),
        );
    }

    /**
     * @return array{scheme: string, host: string, port?: int}|null
     */
    private function devServerUrl(): ?array
    {
        if (app()->isProduction() || ! Vite::isRunningHot()) {
            return null;
        }

        $parts = parse_url(trim((string) @file_get_contents(Vite::hotFile())));

        return isset($parts['scheme'], $parts['host']) ? $parts : null;
    }

    /**
     * @param  array<string, list<string>>  $directives
     */
    private function compile(array $directives): string
    {
        return collect($directives)
            ->map(fn (array $sources, string $name) => $name.' '.implode(' ', $sources))
            ->implode('; ');
    }
}
