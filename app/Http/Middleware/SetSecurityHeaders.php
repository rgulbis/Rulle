<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetSecurityHeaders
{
    /**
     * Blocks the site from being framed by another origin. Without this, a
     * logged-in visitor could be tricked into clicking through an invisible
     * iframe of a real page here (e.g. "cancel subscription", "delete
     * message") layered under attacker-controlled content elsewhere.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');

        return $response;
    }
}
