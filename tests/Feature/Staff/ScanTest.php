<?php

use App\Models\User;

test('non-staff users cannot access the scanner', function () {
    $client = User::factory()->create(['role' => 'client']);

    $response = $this->actingAs($client)->get('/staff/scan');

    $response->assertForbidden();
});

test('staff users can access the scanner', function () {
    $staff = User::factory()->create(['role' => 'staff']);

    $response = $this->actingAs($staff)->get('/staff/scan');

    $response->assertOk();
});

test('scanning a valid qr code toggles check-in status', function () {
    $staff = User::factory()->create(['role' => 'staff']);
    $client = User::factory()->create(['role' => 'client', 'checked_in' => false]);

    $response = $this->actingAs($staff)->postJson('/staff/scan', [
        'code' => $client->qr_code,
    ]);

    $response->assertOk()->assertJson([
        'found' => true,
        'name' => $client->name,
        'checked_in' => true,
    ]);
    expect($client->fresh()->checked_in)->toBeTrue();

    $response = $this->actingAs($staff)->postJson('/staff/scan', [
        'code' => $client->qr_code,
    ]);

    $response->assertOk()->assertJson(['checked_in' => false]);
    expect($client->fresh()->checked_in)->toBeFalse();
});

test('scanning an unknown code returns not found', function () {
    $staff = User::factory()->create(['role' => 'staff']);

    $response = $this->actingAs($staff)->postJson('/staff/scan', [
        'code' => 'does-not-exist',
    ]);

    $response->assertNotFound()->assertJson(['found' => false]);
});

test('each user gets a unique qr code on creation', function () {
    $a = User::factory()->create();
    $b = User::factory()->create();

    expect($a->qr_code)->not->toBeNull();
    expect($a->qr_code)->not->toBe($b->qr_code);
});
