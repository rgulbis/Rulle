<?php

namespace App\Rules;

use App\Support\DisplayName;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A display name that has no invisible, direction-changing or markup
 * characters and isn't a near-copy of another account's name or pending name
 * (see App\Support\DisplayName). Replaces the plain `unique` rules on
 * `users.name` / `users.pending_name`, which only catch exact matches.
 *
 * The unique index on the table stays as the final backstop.
 */
class AvailableDisplayName implements ValidationRule
{
    public function __construct(private readonly ?int $ignoreUserId = null) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // Type problems are the `string` rule's to report.
        if (! is_string($value)) {
            return;
        }

        if (DisplayName::hasForbiddenCharacters($value)) {
            $fail(__('That name contains characters that aren\'t allowed.'));

            return;
        }

        if (DisplayName::isTaken($value, $this->ignoreUserId)) {
            $fail(trans('validation.unique', ['attribute' => trans('validation.attributes.name')]));
        }
    }
}
