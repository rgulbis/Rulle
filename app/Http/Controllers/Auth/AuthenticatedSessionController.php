<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as BaseResponse;

class AuthenticatedSessionController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('auth/login', [
            'status' => session('status'),
        ]);
    }

    public function store(Request $request): BaseResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            return back()->withErrors([
                'email' => 'These credentials do not match our records.',
            ])->onlyInput('email');
        }

        $request->session()->regenerate();

        $url = $request->session()->pull('url.intended', $request->user()->homeUrl());

        // The login form is submitted through Inertia's XHR-based navigation,
        // which expects every redirect to lead to another Inertia page. The
        // admin panel is a separate, non-Inertia (Filament/Livewire) app, so
        // sending Inertia there via a plain redirect makes it try to parse
        // raw Filament HTML as an Inertia response, producing a broken
        // half-rendered page. Inertia::location() forces a real full-page
        // browser navigation instead, the same fix used for Stripe Checkout.
        if (str_starts_with($url, url('/admin'))) {
            return Inertia::location($url);
        }

        return redirect()->to($url);
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
