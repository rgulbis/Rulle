<?php

use App\Http\Middleware\EnsureUserCanScan;
use App\Http\Middleware\EnsureUserIsCustomer;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SetLocale;
use App\Http\Middleware\SetSecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

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

        // Written by the frontend's language toggle in plain JavaScript, so
        // it can't carry Laravel's encryption — and it's only a display
        // preference, nothing worth protecting.
        $middleware->encryptCookies(except: ['locale']);

        $middleware->web(append: [
            SetLocale::class,
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

        // A JSON client asking for something that isn't there (or a route
        // model binding that finds nothing) would otherwise be told which
        // model class failed to load — "No query results for model
        // [App\\Models\\ChatMessage] 99". Say only that it isn't found.
        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            if ($request->expectsJson() && ! $request->header('X-Inertia')) {
                SetLocale::apply($request);

                return response()->json(['message' => __('Not found.')], 404);
            }

            return null;
        });

        // Laravel's stock error pages are plain and unstyled. For the site's
        // own pages, show a proper on-brand one instead (and with the user's
        // chosen language) — but leave the Filament admin, JSON clients, and
        // local debugging (where the real stack trace is what you want) alone.
        // Only full-page loads and Inertia page visits get it: a failed
        // in-page action (a POST/DELETE) keeps its plain error response so
        // the frontend can show a toast without navigating away.
        $exceptions->respond(function (Response $response, Throwable $e, Request $request) {
            $status = $response->getStatusCode();

            if (
                ! in_array($status, [403, 404, 419, 429, 500, 503], true)
                || app()->hasDebugModeEnabled()
                || $request->is('admin', 'admin/*', 'livewire/*', 'api/*')
                || ($request->expectsJson() && ! $request->header('X-Inertia'))
                || ($request->header('X-Inertia') && ! $request->isMethod('GET'))
            ) {
                return $response;
            }

            return Inertia::render('error', ['status' => $status])
                ->toResponse($request)
                ->setStatusCode($status);
        });
    })->create();
