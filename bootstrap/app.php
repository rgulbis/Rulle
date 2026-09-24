<?php

use App\Http\Middleware\EnsureUserCanScan;
use App\Http\Middleware\EnsureUserIsCustomer;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SetSecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // The only real proxy hop in front of this app is the `cloudflared`
        // container relaying Cloudflare Tunnel traffic over the internal
        // Docker network (not Cloudflare's own edge IPs — a Tunnel doesn't
        // expose a public IP to proxy from) — so trusting these private
        // ranges is enough for `X-Forwarded-*` to be honoured from it,
        // without also trusting a spoofed IP from anyone who reaches the
        // app directly (e.g. now that login/register are IP-rate-limited).
        $middleware->trustProxies(at: [
            '10.0.0.0/8',
            '172.16.0.0/12',
            '192.168.0.0/16',
        ]);

        $middleware->web(append: [
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
            SetSecurityHeaders::class,
        ]);

        $middleware->alias([
            'can-scan' => EnsureUserCanScan::class,
            'customer-only' => EnsureUserIsCustomer::class,
        ]);

        // Stripe's webhook requests come from Stripe's servers, not a
        // browser session, so they can't carry a CSRF token.
        $middleware->validateCsrfTokens(except: [
            'stripe/webhook',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
