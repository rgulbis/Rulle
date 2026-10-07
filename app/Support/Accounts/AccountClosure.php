<?php

namespace App\Support\Accounts;

use App\Models\ChatMessage;
use App\Models\CheckInEvent;
use App\Models\Purchase;
use App\Models\Reservation;
use App\Models\User;
use App\Support\Payments\StripeGateway;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Cashier\Subscription;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\InvalidRequestException;
use Throwable;

/**
 * Closing an account never deletes the row. A customer's purchases,
 * reservations, check-ins and payments are the park's records, and the
 * database refuses (`ON DELETE RESTRICT`) to lose them with the user. So the
 * user is soft-deleted and everything that identifies them is replaced:
 * name, email, password, QR code, requested name, saved card details. They
 * can no longer sign in or be found, and their old email is free to register
 * again, while the money and attendance history keeps pointing at a row.
 *
 * Their chat messages and the places they were named as somebody else's
 * participant are personal content rather than records, and are removed.
 */
class AccountClosure
{
    public function __construct(private readonly StripeGateway $stripe) {}

    /**
     * What stands in the way of closing this account, empty if nothing does.
     * `$actor` is whoever is closing it (nobody may close their own account
     * from the admin panel: it is how an admin locks themselves out).
     *
     * @return list<string>
     */
    public function blockers(User $user, ?User $actor = null): array
    {
        $reasons = [];

        if ($actor !== null && $actor->is($user)) {
            $reasons[] = 'You can\'t close your own account.';
        }

        if ($user->isAdmin() && ! User::where('role', 'admin')->where('id', '!=', $user->id)->exists()) {
            $reasons[] = 'This is the last admin account, and the admin panel would be locked.';
        }

        $upcoming = Reservation::where('user_id', $user->id)
            ->where('status', 'active')
            ->where('ends_at', '>', now())
            ->count();

        if ($upcoming > 0) {
            $reasons[] = "They have {$upcoming} upcoming paid ".Str::plural('reservation', $upcoming).'; cancel (and refund) '.($upcoming === 1 ? 'it' : 'them').' first.';
        }

        return $reasons;
    }

    /**
     * @throws AccountClosureBlocked when the account may not be closed
     * @throws ApiErrorException when a subscription could not be cancelled at Stripe (nothing has been changed then)
     */
    public function close(User $user, ?User $actor = null): void
    {
        $this->assertCanClose($user, $actor);

        // Money first, and outside any database transaction: if Stripe
        // refuses, the account is exactly as it was and the admin can retry,
        // instead of an anonymous user who is still being billed.
        $this->cancelSubscriptions($user);

        DB::transaction(function () use ($user, $actor) {
            // Decided again with the write lock held: a reservation paid for,
            // or the second-to-last admin removed, in the meantime counts.
            $this->assertCanClose($user, $actor);

            $this->settleOpenCheckouts($user);
            $this->checkOut($user);

            DB::table('reservation_user')->where('user_id', $user->id)->delete();
            DB::table('sessions')->where('user_id', $user->id)->delete();
            DB::table('password_reset_tokens')->where('email', $user->email)->delete();

            $user->forceFill([
                'name' => $this->placeholderName($user),
                'pending_name' => null,
                'email' => $this->placeholderEmail($user),
                'email_verified_at' => null,
                'password' => Str::random(64),
                'remember_token' => null,
                'qr_code' => (string) Str::uuid(),
                'role' => 'user',
                'chat_muted_until' => null,
                'pm_type' => null,
                'pm_last_four' => null,
            ])->save();

            $user->delete();
        });

        $this->removeChatMessages($user);
        $this->anonymiseAtStripe($user);
    }

    private function assertCanClose(User $user, ?User $actor): void
    {
        $reasons = $this->blockers($user, $actor);

        if ($reasons !== []) {
            throw new AccountClosureBlocked($reasons);
        }
    }

    /**
     * Ends every subscription that is still running, immediately: closing
     * an account is not "cancel at the end of the period", nobody is left to
     * use what has been paid for.
     */
    private function cancelSubscriptions(User $user): void
    {
        $running = Subscription::query()
            ->where('user_id', $user->id)
            ->whereNotIn('stripe_status', ['canceled', 'incomplete_expired'])
            ->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>', now()))
            ->get();

        foreach ($running as $subscription) {
            try {
                $this->stripe->cancelSubscriptionNow($subscription->stripe_id);
            } catch (InvalidRequestException $e) {
                // Already gone at Stripe: that is what was wanted.
                if ($e->getStripeCode() !== 'resource_missing') {
                    throw $e;
                }
            }

            $subscription->forceFill(['stripe_status' => 'canceled', 'ends_at' => now()])->save();
        }
    }

    /**
     * Checkouts the customer never finished can't be completed by a person
     * who no longer exists. (If one is paid anyway, the webhook still sees
     * the row: a reservation is refunded, a pass is honoured.)
     */
    private function settleOpenCheckouts(User $user): void
    {
        Purchase::where('user_id', $user->id)->where('status', 'pending')->update(['status' => 'abandoned']);
        Reservation::where('user_id', $user->id)->where('status', 'pending')->update(['status' => 'cancelled']);
    }

    /**
     * Someone closed while inside the park would otherwise stay counted in
     * the occupancy forever.
     */
    private function checkOut(User $user): void
    {
        if ($user->isCurrentlyCheckedIn()) {
            CheckInEvent::create(['user_id' => $user->id, 'checked_in' => false]);
        }
    }

    /**
     * One at a time through the model, so every open chat window is told the
     * message is gone (and replies that quoted it stop showing the quote).
     */
    private function removeChatMessages(User $user): void
    {
        ChatMessage::where('user_id', $user->id)->each(fn (ChatMessage $message) => $message->delete());
    }

    /**
     * Best effort and after the fact: the account is already closed, and a
     * Stripe outage must not undo that. A failure is logged for a person to
     * fix in the Stripe dashboard.
     */
    private function anonymiseAtStripe(User $user): void
    {
        if (! $user->stripe_id) {
            return;
        }

        try {
            $this->stripe->anonymiseCustomer($user->stripe_id, $user->name, $user->email);
        } catch (Throwable $e) {
            Log::warning('A closed account\'s Stripe customer still carries personal details; clear them in the Stripe dashboard.', [
                'user_id' => $user->id,
                'stripe_customer' => $user->stripe_id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function placeholderName(User $user): string
    {
        return "Deleted user {$user->id}";
    }

    private function placeholderEmail(User $user): string
    {
        return "deleted-{$user->id}@deleted.invalid";
    }
}
