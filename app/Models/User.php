<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Cashier\Billable;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string $role
 * @property string $qr_code
 * @property bool $checked_in
 * @property Carbon|null $chat_muted_until
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
// 'role' is deliberately left out — it's a privilege boundary, not just
// another profile field, so it should never be settable via a mass-assigned
// array built from request input. The admin panel (the only place a role is
// meant to change) sets it via forceFill/forceCreate instead — see
// App\Filament\Resources\Users\Pages\CreateUser and EditUser.
#[Fillable(['name', 'email', 'password', 'email_verified_at', 'chat_muted_until'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser, MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use Billable, HasFactory, Notifiable;

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
     * For showing an email address to someone other than its owner (e.g.
     * disambiguating same-named results in a customer search) without
     * handing out the full address — enough is visible to tell two people
     * apart, not enough to be scraped as a usable contact list.
     */
    public static function maskEmail(string $email): string
    {
        if (! str_contains($email, '@')) {
            return $email;
        }

        [$local, $domain] = explode('@', $email, 2);

        return mb_substr($local, 0, 1).'***@'.$domain;
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
            'checked_in' => 'boolean',
            'chat_muted_until' => 'datetime',
        ];
    }
}
