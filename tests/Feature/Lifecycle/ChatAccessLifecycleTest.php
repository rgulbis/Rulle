<?php

use App\Models\ChatMessage;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Broadcasting\Broadcasters\Broadcaster;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\DB;

/*
| A group chat belongs to a reservation that is paid for and not long over.
| Once that stops being true (the owner cancels, a checkout expires, a week
| passes) nobody should be able to get back in by typing the address, whoever
| they are and whichever of the chat's routes they try. These go through every
| route for every kind of visitor, with messages already in the chat.
*/

/**
 * A reservation whose chat was open (or never could be) and has since ended
 * the given way, with a message in it and a friend who had joined.
 *
 * @return array{Reservation, ChatMessage, array<string, User>}
 */
function chatThatEnded(string $how): array
{
    $people = [
        'owner' => User::factory()->create(),
        'participant' => User::factory()->create(),
        'outsider' => User::factory()->create(),
        'staff' => User::factory()->create(['role' => 'employee']),
        'admin' => User::factory()->create(['role' => 'admin']),
    ];

    // An expired checkout is a reservation that was never paid for, so it
    // never had a chat; a stray row is in it anyway, to prove nothing reaches it.
    $start = $how === 'ended long ago' ? now()->subDays(9) : now()->addDays(3);
    $reservation = makeReservation($people['owner'], $start, $start->copy()->addHour(), [
        'status' => $how === 'checkout expired' ? 'pending' : 'active',
        'stripe_checkout_session_id' => 'cs_chat',
    ]);
    $reservation->invitations()->attach($people['participant']->id, ['status' => 'accepted']);
    $message = ChatMessage::create(['user_id' => $people['participant']->id, 'reservation_id' => $reservation->id, 'body' => 'see you there']);

    if ($how === 'cancelled by the owner') {
        fakeStripe()->addSession('cs_chat');
        test()->actingAs($people['owner'])->delete("/reservations/{$reservation->id}")->assertRedirect(route('reservations.index'));
    }

    if ($how === 'checkout expired') {
        config(['cashier.webhook.secret' => null]);
        test()->postJson('/stripe/webhook', ['type' => 'checkout.session.expired', 'data' => ['object' => ['id' => 'cs_chat']]])->assertOk();
    }

    return [$reservation->fresh(), $message, $people];
}

function chatChannelAllows(User $user, Reservation $reservation): bool
{
    $channels = (new ReflectionProperty(Broadcaster::class, 'channels'))->getValue(Broadcast::driver());

    return (bool) $channels['reservation.{reservationId}.chat']($user, (string) $reservation->id);
}

/**
 * Every way into a reservation's chat: [method, uri]. `$friend` is the one
 * who wrote the message, so the moderation routes have someone to act on.
 *
 * @return list<array{string, string}>
 */
function chatRoutes(Reservation $reservation, ChatMessage $message, User $friend): array
{
    $base = "/reservations/{$reservation->id}/chat";

    return [
        ['GET', $base],
        ['POST', $base],
        ['DELETE', "{$base}/{$message->id}"],
        ['POST', "{$base}/{$message->id}/pin"],
        ['DELETE', "{$base}/{$message->id}/pin"],
        ['POST', "{$base}/users/{$friend->id}/mute"],
        ['POST', "{$base}/users/{$friend->id}/unmute"],
    ];
}

test('once a chat is over nobody can get back in by its address, whoever they are and whatever they try', function (string $how) {
    [$reservation, $message, $people] = chatThatEnded($how);

    $before = [
        'messages' => ChatMessage::count(),
        'pinned' => ChatMessage::whereNotNull('pinned_at')->count(),
        'mutes' => DB::table('reservation_users')->whereNotNull('chat_muted_until')->count(),
    ];

    foreach ($people as $role => $person) {
        foreach (chatRoutes($reservation, $message, $people['participant']) as [$method, $uri]) {
            $params = match (true) {
                $method === 'POST' && str_ends_with($uri, '/chat') => ['body' => 'let me in'],
                str_ends_with($uri, '/mute') => ['hours' => 1],
                default => [],
            };

            $response = $this->actingAs($person)->call($method, $uri, $params);

            // Staff and admins never get as far as the reservation pages
            // (they are sent back to their own home); customers are refused.
            if (in_array($role, ['staff', 'admin'], true)) {
                expect($response->getStatusCode())->toBe(302)->and($response->headers->get('Location'))->not->toContain('/chat');
            } else {
                expect($response->getStatusCode())->toBe(403, "{$role} was not turned away from {$method} {$uri} ({$how})");
            }
        }

        expect(chatChannelAllows($person, $reservation))->toBeFalse("{$role} may still listen on the websocket ({$how})");
    }

    // A guest is sent to log in, not shown anything.
    auth()->guard('web')->forgetUser();
    $this->get("/reservations/{$reservation->id}/chat")->assertRedirect('/login');
    $this->post("/reservations/{$reservation->id}/chat", ['body' => 'hello'])->assertRedirect('/login');

    // Nothing was written, and the chat is gone from everyone's sidebar.
    expect([
        'messages' => ChatMessage::count(),
        'pinned' => ChatMessage::whereNotNull('pinned_at')->count(),
        'mutes' => DB::table('reservation_users')->whereNotNull('chat_muted_until')->count(),
    ])->toBe($before);
    foreach (['owner', 'participant'] as $role) {
        expect(Reservation::chatGroupsFor($people[$role]))->toHaveCount(0);
        $this->actingAs($people[$role])->get('/chat')->assertInertia(fn ($page) => $page->has('chatGroups', 0));
    }
})->with(['cancelled by the owner', 'checkout expired', 'ended long ago']);

test('a message from one reservation cannot be reached through the chat of another', function () {
    $owner = User::factory()->create();
    $friend = User::factory()->create();
    $mine = makeReservation($owner, now()->addDay(), now()->addDay()->addHour());
    $theirs = makeReservation(User::factory()->create(), now()->addDays(2), now()->addDays(2)->addHour());
    $theirs->invitations()->attach($friend->id, ['status' => 'accepted']);
    $theirMessage = ChatMessage::create(['user_id' => $friend->id, 'reservation_id' => $theirs->id, 'body' => 'private']);
    $globalMessage = ChatMessage::create(['user_id' => $friend->id, 'body' => 'public']);

    foreach ([$theirMessage, $globalMessage] as $foreign) {
        $this->actingAs($owner)->delete("/reservations/{$mine->id}/chat/{$foreign->id}")->assertNotFound();
        $this->actingAs($owner)->post("/reservations/{$mine->id}/chat/{$foreign->id}/pin")->assertNotFound();
    }

    expect(ChatMessage::count())->toBe(2)->and(ChatMessage::whereNotNull('pinned_at')->count())->toBe(0);
});

test('a chat that is still open stays open to the people in it after the checkout-expired event for some other session', function () {
    $owner = User::factory()->create();
    $reservation = makeReservation($owner, now()->addDay(), now()->addDay()->addHour(), ['stripe_checkout_session_id' => 'cs_live']);

    // Stripe expiring an old, unrelated session must not close a running chat.
    config(['cashier.webhook.secret' => null]);
    $this->postJson('/stripe/webhook', ['type' => 'checkout.session.expired', 'data' => ['object' => ['id' => 'cs_some_old_one']]])->assertOk();

    $this->actingAs($owner)->get("/reservations/{$reservation->id}/chat")->assertOk();
    expect(chatChannelAllows($owner, $reservation))->toBeTrue();
});
