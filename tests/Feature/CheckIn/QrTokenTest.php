<?php

use App\Models\CheckInEvent;
use App\Models\Purchase;
use App\Models\UsedQrToken;
use App\Models\User;
use App\Support\Accounts\AccountClosure;
use App\Support\CheckIn\QrToken;
use App\Support\CheckIn\QrTokenProblem;
use App\Support\CheckIn\VerifiedQrToken;

function scan(User $staff, string $code, string $mode = 'entry')
{
    return test()->actingAs($staff)->postJson('/staff/scan', ['code' => $code, 'mode' => $mode]);
}

function giveUnlimitedAccess(User $user): void
{
    Purchase::create([
        'user_id' => $user->id,
        'subscription_type_id' => makeSubscriptionType(['unlimited_entries' => true, 'visit_limit' => null])->id,
        'stripe_checkout_session_id' => 'cs_'.$user->id,
        'status' => 'active',
        'valid_date' => now()->toDateString(),
    ]);
}

test('an issued token verifies back to its owner and lives for the configured time', function () {
    config(['checkin.token_ttl_seconds' => 60]);
    $user = User::factory()->create();

    $issued = QrToken::issue($user);
    $verified = QrToken::verify($issued['token']);

    expect($issued['ttl'])->toBe(60)
        ->and($verified)->toBeInstanceOf(VerifiedQrToken::class)
        ->and($verified->user->is($user))->toBeTrue()
        ->and($verified->expiresAt)->toBe(now()->getTimestamp() + 60);
});

test('every token is different, and none contains the stored qr_code secret', function () {
    $user = User::factory()->create();

    $a = QrToken::issue($user)['token'];
    $b = QrToken::issue($user)['token'];

    expect($a)->not->toBe($b)
        ->and($a)->not->toContain($user->qr_code);
});

test('a token stops verifying once it has expired', function () {
    $user = User::factory()->create();
    $token = QrToken::issue($user)['token'];

    $this->travel(59)->seconds();
    expect(QrToken::verify($token))->toBeInstanceOf(VerifiedQrToken::class);

    $this->travel(2)->seconds();
    expect(QrToken::verify($token))->toBe(QrTokenProblem::Expired);
});

test('a tampered token is invalid', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $token = QrToken::issue($user)['token'];
    [$v, $id, $exp, $nonce, $sig] = explode('.', $token);

    expect(QrToken::verify("$v.$other->id.$exp.$nonce.$sig"))->toBe(QrTokenProblem::Invalid)
        ->and(QrToken::verify("$v.$id.".($exp + 3600).".$nonce.$sig"))->toBe(QrTokenProblem::Invalid)
        ->and(QrToken::verify("$v.$id.$exp.".str_repeat('a', 24).".$sig"))->toBe(QrTokenProblem::Invalid)
        ->and(QrToken::verify("$v.$id.$exp.$nonce.".str_repeat('A', 43)))->toBe(QrTokenProblem::Invalid)
        ->and(QrToken::verify('not a token'))->toBe(QrTokenProblem::Invalid)
        ->and(QrToken::verify($user->qr_code))->toBe(QrTokenProblem::Invalid);
});

test('a token signed under another app key is invalid', function () {
    $user = User::factory()->create();
    $token = QrToken::issue($user)['token'];

    config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);

    expect(QrToken::verify($token))->toBe(QrTokenProblem::Invalid);
});

test('closing an account kills the tokens it had out', function () {
    fakeStripe();
    $user = User::factory()->create();
    $token = QrToken::issue($user)['token'];

    app(AccountClosure::class)->close($user);

    expect(QrToken::verify($token))->toBe(QrTokenProblem::Invalid);
});

test('the dashboard never sends the qr_code secret to the page', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/dashboard')->assertInertia(fn ($page) => $page
        ->missing('auth.user.qr_code')
        ->has('qrToken.token')
        ->where('qrToken.ttl', 60)
    );

    expect($user->toArray())->not->toHaveKey('qr_code');
});

test('an unverified customer gets no token on the dashboard and none from the endpoint', function () {
    $user = User::factory()->unverified()->create();

    $this->actingAs($user)->get('/dashboard')->assertInertia(fn ($page) => $page->where('qrToken', null));
    $this->actingAs($user)->getJson('/dashboard/qr-token')->assertForbidden();
});

test('the token endpoint hands out a fresh token every call, never cached', function () {
    $user = User::factory()->create();

    $first = $this->actingAs($user)->getJson('/dashboard/qr-token');
    $second = $this->actingAs($user)->getJson('/dashboard/qr-token');

    $first->assertOk()->assertJsonStructure(['token', 'ttl'])->assertHeader('Cache-Control', 'no-store, private');
    expect($first->json('token'))->not->toBe($second->json('token'))
        ->and(QrToken::verify($first->json('token')))->toBeInstanceOf(VerifiedQrToken::class);
});

test('the token endpoint is for logged-in customers only', function () {
    $this->getJson('/dashboard/qr-token')->assertUnauthorized();
    $this->actingAs(User::factory()->create(['role' => 'employee']))->getJson('/dashboard/qr-token')->assertRedirect();
});

test('a token lets a rider in once and cannot be used again, even to check them out', function () {
    $staff = User::factory()->create(['role' => 'employee']);
    $client = User::factory()->create();
    giveUnlimitedAccess($client);
    $token = qrTokenFor($client);

    scan($staff, $token, 'entry')->assertOk()->assertJson(['allowed' => true, 'checked_in' => true]);
    expect(UsedQrToken::count())->toBe(1);

    // A copy of the same screen, scanned for exit within its minute.
    scan($staff, $token, 'exit')
        ->assertStatus(422)
        ->assertJson(['found' => true, 'allowed' => false]);
    expect($client->isCurrentlyCheckedIn())->toBeTrue();

    // The rider's next code works.
    scan($staff, qrTokenFor($client), 'exit')->assertOk()->assertJson(['checked_in' => false]);
});

test('an expired token is refused without checking anyone in', function () {
    $staff = User::factory()->create(['role' => 'employee']);
    $client = User::factory()->create();
    giveUnlimitedAccess($client);
    $token = qrTokenFor($client);

    $this->travel(61)->seconds();

    scan($staff, $token)->assertStatus(422)->assertJson(['found' => false]);
    expect($client->isCurrentlyCheckedIn())->toBeFalse()
        ->and(CheckInEvent::count())->toBe(0);
});

test('the static qr_code value no longer works as a code', function () {
    $staff = User::factory()->create(['role' => 'employee']);
    $client = User::factory()->create();
    giveUnlimitedAccess($client);

    scan($staff, $client->qr_code)->assertNotFound()->assertJson(['found' => false]);
    expect($client->isCurrentlyCheckedIn())->toBeFalse();
});

test('a refused scan does not spend the token, so the right mode can be tried next', function () {
    $staff = User::factory()->create(['role' => 'employee']);
    $client = User::factory()->create();
    giveUnlimitedAccess($client);
    $token = qrTokenFor($client);

    // Staff left it on exit by mistake.
    scan($staff, $token, 'exit')->assertStatus(409);
    expect(UsedQrToken::count())->toBe(0);

    scan($staff, $token, 'entry')->assertOk()->assertJson(['checked_in' => true]);
});

test('a denied entry (no pass) leaves the token unspent', function () {
    $staff = User::factory()->create(['role' => 'employee']);
    $client = User::factory()->create();

    scan($staff, qrTokenFor($client))->assertForbidden();

    expect(UsedQrToken::count())->toBe(0);
});

test('spent token records are pruned once they would have expired anyway', function () {
    $staff = User::factory()->create(['role' => 'employee']);
    $client = User::factory()->create();
    giveUnlimitedAccess($client);
    scan($staff, qrTokenFor($client))->assertOk();

    $this->artisan('model:prune', ['--model' => [UsedQrToken::class]])->assertSuccessful();
    expect(UsedQrToken::count())->toBe(1);

    $this->travel(2)->minutes();
    $this->artisan('model:prune', ['--model' => [UsedQrToken::class]])->assertSuccessful();
    expect(UsedQrToken::count())->toBe(0);
});
