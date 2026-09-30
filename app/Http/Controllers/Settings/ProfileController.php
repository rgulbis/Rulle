<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Rules\NoInappropriateContent;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    public function edit(): Response
    {
        return Inertia::render('settings/profile', [
            'status' => session('status'),
        ]);
    }

    /**
     * Only the display name for now — it's what other riders and staff see
     * in chat and reservation groups. Email stays as registered, since it's
     * also the login and the verified address.
     */
    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', new NoInappropriateContent],
        ]);

        $request->user()->update(['name' => trim($validated['name'])]);

        return back()->with('status', 'profile-updated');
    }
}
