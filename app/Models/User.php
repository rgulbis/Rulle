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
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'email', 'password', 'role', 'email_verified_at'])]
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

    public function homeUrl(): string
    {
        return match (true) {
            $this->isAdmin() => '/admin',
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
        ];
    }
}
