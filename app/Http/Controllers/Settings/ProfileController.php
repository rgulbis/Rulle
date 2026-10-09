<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Rules\AvailableDisplayName;
use App\Rules\NoInappropriateContent;
use Illuminate\Database\UniqueConstraintViolationException;
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
            'pendingName' => request()->user()->pending_name,
        ]);
    }

    /**
     * Only the display name for now - it's what other riders and staff see
     * in chat and reservation groups. Email stays as registered, since it's
     * also the login and the verified address.
     *
     * A name change doesn't take effect immediately: the word list in
     * NoInappropriateContent only refuses the most obvious abuse and is easy
     * to get around, so every change goes to pending_name for an admin to
     * approve or reject first - see Filament\Resources\Users\Tables\UsersTable.
     * `name` itself (what actually shows everywhere) never changes here.
     */
    public function update(Request $request): RedirectResponse
    {
        $user = $request->user();

        // Trimmed before validation (not after), so "Jane " can't dodge the
        // uniqueness check below by comparing as a different string from
        // "Jane" and then colliding with it anyway once trimmed for storage.
        // Only a string is trimmed: anything else is left for the `string`
        // rule to refuse, instead of blowing up on an array cast.
        if (is_string($request->input('name'))) {
            $request->merge(['name' => trim($request->input('name'))]);
        }

        $validated = $request->validate([
            'name' => [
                'required', 'string', 'max:255', new NoInappropriateContent,
                // Also covers other people's pending names, and refuses
                // near-copies (case, lookalike letters) and invisible or
                // direction-changing characters - see DisplayName.
                new AvailableDisplayName($user->id),
            ],
        ]);

        $name = $validated['name'];

        // Back to the name they already have - nothing to review, and
        // clears out any earlier request that's now moot.
        try {
            $user->update(['pending_name' => $name === $user->name ? null : $name]);
        } catch (UniqueConstraintViolationException) {
            // Someone else asked for the same name in the same instant: the
            // unique index on pending_name let only one request through.
            return back()->withErrors(['name' => trans('validation.unique', ['attribute' => trans('validation.attributes.name')])]);
        }

        return back()->with(
            'status',
            $name === $user->name ? 'profile-updated' : 'profile-pending',
        );
    }
}
