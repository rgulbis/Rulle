<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Rules\AvailableDisplayName;
use App\Rules\NoInappropriateContent;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

class RegisteredUserController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('auth/register');
    }

    public function store(Request $request): RedirectResponse
    {
        // Normalised before validation, so the uniqueness check and the stored
        // value are both the ASCII (punycode) form of a Unicode domain, the
        // same form login looks it up by. Name is trimmed for the same
        // reason the profile form does it: "Jane " mustn't dodge uniqueness.
        // Only strings are touched: anything else is left for the `string`
        // rules to refuse, instead of blowing up on an array cast.
        if (is_string($request->input('name'))) {
            $request->merge(['name' => trim($request->input('name'))]);
        }

        if (is_string($request->input('email'))) {
            $request->merge(['email' => User::normalizeEmailForLookup(trim($request->input('email')))]);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', new NoInappropriateContent, new AvailableDisplayName],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            // Skip the real verification step outside production so local
            // testing doesn't depend on receiving an actual email.
            'email_verified_at' => app()->isProduction() ? null : now(),
        ]);

        event(new Registered($user));

        Auth::login($user);

        return redirect()->route('dashboard');
    }
}
