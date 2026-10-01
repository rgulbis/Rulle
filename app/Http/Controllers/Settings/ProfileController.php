<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Rules\NoInappropriateContent;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
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
     * Only the display name for now — it's what other riders and staff see
     * in chat and reservation groups. Email stays as registered, since it's
     * also the login and the verified address.
     *
     * A name change doesn't take effect immediately: the profanity filter
     * catches overt abuse, but not everything (a name that's only crude in
     * context, for instance), so it goes to pending_name for an admin to
     * approve or reject first — see Filament\Resources\Users\Tables\UsersTable.
     * `name` itself (what actually shows everywhere) never changes here.
     */
    public function update(Request $request): RedirectResponse
    {
        $user = $request->user();

        // Trimmed before validation (not after), so "Jane " can't dodge the
        // uniqueness checks below by comparing as a different string from
        // "Jane" and then colliding with it anyway once trimmed for storage.
        $request->merge(['name' => trim((string) $request->input('name'))]);

        $validated = $request->validate([
            'name' => [
                'required', 'string', 'max:255', new NoInappropriateContent,
                Rule::unique('users', 'name')->ignore($user->id),
                // The built-in unique rule above only covers the `name`
                // column — this closes the matching gap on pending_name, or
                // two people could both have the same name approved out
                // from under them.
                function (string $attribute, mixed $value, Closure $fail) use ($user) {
                    $taken = User::where('id', '!=', $user->id)
                        ->where('pending_name', $value)
                        ->exists();

                    if ($taken) {
                        $fail(trans('validation.unique', ['attribute' => trans('validation.attributes.name')]));
                    }
                },
            ],
        ]);

        $name = $validated['name'];

        // Back to the name they already have — nothing to review, and
        // clears out any earlier request that's now moot.
        $user->update(['pending_name' => $name === $user->name ? null : $name]);

        return back()->with(
            'status',
            $name === $user->name ? 'profile-updated' : 'profile-pending',
        );
    }
}
