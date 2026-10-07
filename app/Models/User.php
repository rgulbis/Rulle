<?php

namespace App\Models;

use App\Support\CheckInOccupancy;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Cashier\Billable;

/**
 * @property int $id
 * @property string $name
 * @property string|null $pending_name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string $role
 * @property string $qr_code
 * @property Carbon|null $chat_muted_until
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
// 'role' is deliberately left out — it's a privilege boundary, not just
// another profile field, so it should never be settable via a mass-assigned
// array built from request input. The admin panel (the only place a role is
// meant to change) sets it via forceFill/forceCreate instead — see
// App\Filament\Resources\Users\Pages\CreateUser and EditUser.
#[Fillable(['name', 'pending_name', 'email', 'password', 'email_verified_at', 'chat_muted_until'])]
// qr_code is the secret the entry tokens are signed with (see
// App\Support\CheckIn\QrToken) — it must never be serialized to a page.
#[Hidden(['password', 'remember_token', 'qr_code'])]
class User extends Authenticatable implements FilamentUser, MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use Billable, HasFactory, Notifiable, SoftDeletes;

    protected static function booted(): void
    {
        static::creating(function (User $user) {
            $user->qr_code ??= (string) Str::uuid();
        });
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function isEmployee(): bool
    {
        return $this->role === 'employee';
    }

    public function canScan(): bool
    {
        return $this->isAdmin() || $this->isEmployee();
    }

    public function isCustomer(): bool
    {
        return $this->role === 'user';
    }

    /**
     * Strictly higher in the role hierarchy (customer < employee < admin).
     * Equal ranks don't outrank each other, so nobody outranks themselves.
     */
    public function outranks(User $other): bool
    {
        $ranks = ['user' => 0, 'employee' => 1, 'admin' => 2];

        return ($ranks[$this->role] ?? 0) > ($ranks[$other->role] ?? 0);
    }

    public function isChatMuted(): bool
    {
        return $this->chat_muted_until !== null && $this->chat_muted_until->isFuture();
    }

    /**
     * Makes the requested name the real one — unless somebody else has
     * taken it since the request was made (it was unique when they asked,
     * which says nothing about now). Then the request is dropped instead and
     * false is returned, so the caller can tell the admin why nothing
     * changed. The check and the change are one transaction, and the unique
     * index on `users.name` is the backstop if anything ever slips between.
     */
    public function approvePendingName(): bool
    {
        try {
            return DB::transaction(function (): bool {
                $this->refresh();

                $name = $this->pending_name;

                if ($name === null) {
                    return false;
                }

                $taken = static::withTrashed()
                    ->where('id', '!=', $this->id)
                    ->where('name', $name)
                    ->exists();

                if ($taken) {
                    $this->update(['pending_name' => null]);

                    return false;
                }

                $this->update(['name' => $name, 'pending_name' => null]);

                return true;
            });
        } catch (UniqueConstraintViolationException) {
            $this->update(['pending_name' => null]);

            return false;
        }
    }

    public function rejectPendingName(): void
    {
        $this->update(['pending_name' => null]);
    }

    /**
     * A browser doesn't convert a Unicode domain typed into a form field the
     * way it converts one typed into the address bar, so "admin@rullē.lv"
     * and "admin@xn--rull-eva.lv" are literally different strings as far as
     * a plain DB lookup is concerned, even though they're the same address.
     * Login forms should run the submitted email through this before
     * looking it up, so either form works.
     */
    public static function normalizeEmailForLookup(string $email): string
    {
        if (! str_contains($email, '@')) {
            return $email;
        }

        [$local, $domain] = explode('@', $email, 2);

        if (! preg_match('/[^\x00-\x7F]/', $domain)) {
            return $email;
        }

        $ascii = idn_to_ascii($domain, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);

        return $ascii ? "{$local}@{$ascii}" : $email;
    }

    /**
     * Why this user's role can't be changed to `$newRole` by `$actor`, or
     * null if it can. An admin can't demote themselves (it is how an admin
     * locks themselves out of the panel), and the last admin can't be
     * demoted by anyone, or nobody could manage the park again.
     */
    public function roleChangeBlocker(string $newRole, ?User $actor = null): ?string
    {
        if (! $this->isAdmin() || $newRole === 'admin') {
            return null;
        }

        if ($actor !== null && $actor->is($this)) {
            return 'You can\'t remove your own admin role. Ask another admin to do it.';
        }

        if (! static::where('role', 'admin')->where('id', '!=', $this->id)->exists()) {
            return 'This is the last admin account, and the admin panel would be locked.';
        }

        return null;
    }

    public function homeUrl(): string
    {
        return match (true) {
            $this->isAdmin() => url('/admin'),
            $this->isEmployee() => route('staff.scan'),
            default => route('dashboard'),
        };
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return $this->isAdmin();
    }

    /**
     * @return HasMany<Purchase, $this>
     */
    public function purchases(): HasMany
    {
        return $this->hasMany(Purchase::class);
    }

    public function activeOneTimePurchase(): ?Purchase
    {
        return $this->purchases()
            ->where('status', 'active')
            ->with('subscriptionType')
            ->get()
            ->first(fn (Purchase $purchase) => $purchase->isCurrentlyUsable());
    }

    public function hasActiveAccess(): bool
    {
        return $this->subscribed('default') || $this->activeOneTimePurchase() !== null;
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'chat_muted_until' => 'datetime',
        ];
    }

    /**
     * Whether this user is currently inside the park, derived from their
     * most recent check_in_events row rather than a cached column — see
     * App\Support\CheckInOccupancy for the equivalent count across everyone.
     * A check-in older than the longest plausible visit no longer counts.
     */
    public function isCurrentlyCheckedIn(): bool
    {
        // Ordered by id, not created_at: two events landing in the same
        // timestamp (SQLite's precision is only to the second) would
        // otherwise tie, and id is always a reliable insertion order.
        $latest = CheckInEvent::where('user_id', $this->id)->orderByDesc('id')->first(['checked_in', 'created_at']);

        return $latest !== null
            && $latest->checked_in
            && $latest->created_at->gte(CheckInOccupancy::staleBefore());
    }
}
