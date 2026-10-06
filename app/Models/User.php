<?php

namespace App\Models;

use App\Support\Payments\StripeGateway;
use Database\Factories\UserFactory;
use DomainException;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
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
 */
// 'role' is deliberately left out — it's a privilege boundary, not just
// another profile field, so it should never be settable via a mass-assigned
// array built from request input. The admin panel (the only place a role is
// meant to change) sets it via forceFill/forceCreate instead — see
// App\Filament\Resources\Users\Pages\CreateUser and EditUser.
#[Fillable(['name', 'pending_name', 'email', 'password', 'email_verified_at', 'chat_muted_until'])]
// qr_code is the secret the entry tokens are minted from — never serialised
// (it used to ride along in every Inertia page's props).
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

        // Accounts are closed (soft-deleted + anonymised), never erased:
        // purchases, paid reservations and check-ins are the business's
        // financial and attendance record. The database enforces the same
        // thing with restrictive foreign keys.
        static::forceDeleting(function (User $user) {
            throw new DomainException('User accounts are closed, not erased — their payment and attendance history must be kept.');
        });
    }

    /**
     * Why this account can't be closed right now, or null if it can.
     */
    public function closureBlocker(?User $actor = null): ?string
    {
        if ($actor && $actor->is($this)) {
            return 'You cannot close your own account.';
        }

        if ($this->isAdmin() && static::where('role', 'admin')->count() <= 1) {
            return 'This is the last administrator — promote someone else first.';
        }

        $upcomingPaid = Reservation::where('user_id', $this->id)
            ->where('status', 'active')
            ->where('ends_at', '>', now())
            ->exists();

        if ($upcomingPaid) {
            return 'This user owns upcoming paid reservations — cancel (and refund) them first.';
        }

        return null;
    }

    /**
     * The only way an account goes away. Order matters: Stripe first, so if
     * ending the subscription fails nothing local has changed and the admin
     * can retry — we never end up with a deleted user Stripe keeps charging.
     * Then, in one transaction, the person is anonymised (no personal data
     * left, login impossible, QR code dead) and soft-deleted, while every
     * purchase, reservation, payment and check-in row stays intact.
     *
     * @throws DomainException when closure is blocked
     */
    public function closeAccount(?User $actor = null): void
    {
        if ($blocker = $this->closureBlocker($actor)) {
            throw new DomainException($blocker);
        }

        $liveSubscriptions = fn () => $this->subscriptions()->whereNotIn('stripe_status', ['canceled', 'incomplete_expired']);
        $stripeIds = $liveSubscriptions()->pluck('stripe_id');

        foreach ($stripeIds as $stripeId) {
            app(StripeGateway::class)->cancelSubscriptionNow($stripeId);
        }

        DB::transaction(function () use ($liveSubscriptions) {
            $liveSubscriptions()->update(['stripe_status' => 'canceled', 'ends_at' => now()]);

            // Never leave someone counted as "inside" after their account is gone.
            if ($this->isCurrentlyCheckedIn()) {
                CheckInEvent::create(['user_id' => $this->id, 'checked_in' => false]);
            }

            DB::table('reservation_user')->where('user_id', $this->id)->delete();

            $this->forceFill([
                'name' => "Deleted user {$this->id}",
                'pending_name' => null,
                'email' => "deleted-{$this->id}@deleted.invalid",
                'password' => Str::random(64),
                'remember_token' => null,
                'email_verified_at' => null,
                'qr_code' => (string) Str::uuid(),
                'role' => 'user',
                'chat_muted_until' => null,
            ])->save();

            $this->delete();
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
     * @throws DomainException if someone else took the name in the meantime —
     *                         uniqueness was checked when it was requested,
     *                         but admins can rename accounts in between.
     */
    public function approvePendingName(): void
    {
        $taken = static::where('id', '!=', $this->id)->where('name', $this->pending_name)->exists();

        if ($taken) {
            throw new DomainException('That name is now used by another account — reject the request instead.');
        }

        $this->update(['name' => $this->pending_name, 'pending_name' => null]);
    }

    public function rejectPendingName(): void
    {
        $this->update(['pending_name' => null]);
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
            'chat_muted_until' => 'datetime',
        ];
    }

    /**
     * Whether this user is currently inside the park, derived from their
     * most recent check_in_events row rather than a cached column — see
     * App\Support\CheckInOccupancy for the equivalent count across everyone.
     */
    public function isCurrentlyCheckedIn(): bool
    {
        // Ordered by id, not created_at: two events landing in the same
        // timestamp (SQLite's precision is only to the second) would
        // otherwise tie, and id is always a reliable insertion order.
        return (bool) CheckInEvent::where('user_id', $this->id)->orderByDesc('id')->value('checked_in');
    }
}
