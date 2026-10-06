<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetSecurityHeaders
{
    /**
     * Browser-enforced defences for every response:
     *
     *  - framing is blocked (otherwise a logged-in visitor could be tricked
     *    into clicking through an invisible iframe of a real page here);
     *  - a Content-Security-Policy limits what a page may load or run, so an
     *    injected script has nowhere to run or send data;
     *  - no MIME sniffing, a conservative Referer policy, camera access only
     *    for this site (the QR scanner), and HSTS over HTTPS in production.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $headers = $response->headers;

        $headers->set('X-Frame-Options', 'SAMEORIGIN');
        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $headers->set('Permissions-Policy', 'camera=(self), microphone=(), geolocation=(), payment=(), usb=()');
        $headers->set('Content-Security-Policy', $this->contentSecurityPolicy($request));

        if (app()->isProduction() && $request->isSecure()) {
            $headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }

    private function contentSecurityPolicy(Request $request): string
    {
        $host = $request->getHost();
        $port = $request->getPort();
        $isFilament = $request->is('admin', 'admin/*', 'livewire', 'livewire/*', 'livewire-*');

        // The customer-facing site is a Vite bundle plus data attributes — no
        // inline script at all, so scripts are same-origin files only. The
        // admin panel (Filament/Livewire/Alpine) relies on inline scripts and
        // Alpine's expression evaluator, so it gets a looser script policy.
        $script = $isFilament ? "'self' 'unsafe-inline' 'unsafe-eval'" : "'self'";
        $style = "'self' 'unsafe-inline'";
        $font = "'self' data:";
        $img = "'self' data: blob:";
        // Live chat/occupancy over Reverb: the frontend connects to
        // window.location.hostname (on 443 behind the tunnel), so wss on this
        // host. Plain ws on any port is only allowed when the page itself isn't
        // on HTTPS, i.e. a local/staging run with Reverb on its own port.
        $connect = "'self' wss://{$host}".($port && ! in_array($port, [80, 443], true) ? ":{$port}" : '');
        if (! $request->isSecure()) {
            $connect .= " ws://{$host}:*";
        }

        // `npm run dev`: scripts, styles, HMR websocket and assets come from
        // the Vite dev server instead of public/build.
        if (app()->environment('local') && is_file(public_path('hot'))) {
            $vite = rtrim(trim((string) file_get_contents(public_path('hot'))), '/');
            $viteWs = preg_replace('#^http#', 'ws', $vite);
            // The React refresh preamble is an inline script — dev only, never
            // present in the production build.
            $script .= " 'unsafe-inline' {$vite}";
            $style .= " {$vite}";
            $font .= " {$vite}";
            $img .= " {$vite}";
            $connect .= " {$vite} {$viteWs}";
        }

        $directives = [
            "default-src 'self'",
            "script-src {$script}",
            "style-src {$style}",
            "img-src {$img}",
            "font-src {$font}",
            "connect-src {$connect}",
            // The livestream: hls.js feeds segments to the <video> element
            // through a blob: MediaSource, in a worker.
            "media-src 'self' blob:",
            "worker-src 'self' blob:",
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'self'",
        ];

        if (app()->isProduction()) {
            $directives[] = 'upgrade-insecure-requests';
        }

        return implode('; ', $directives);
    }
}
