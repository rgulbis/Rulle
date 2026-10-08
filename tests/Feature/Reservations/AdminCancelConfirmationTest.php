<?php

use App\Filament\Resources\Reservations\Pages\ListReservations;
use App\Models\User;
use Filament\Actions\Action;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create(['role' => 'admin']));
    $this->owner = User::factory()->create();
});

test('cancelling a paid upcoming reservation says it will be refunded, and how much', function () {
    $reservation = makeReservation($this->owner, now()->addDays(2), now()->addDays(2)->addHour(), ['price_cents' => 4500]);

    Livewire::test(ListReservations::class)
        ->assertTableActionExists('cancel', fn (Action $action) => str_contains((string) $action->getModalDescription(), 'refunded €45.00 in full'), $reservation);
});

test('cancelling an unpaid reservation says nothing is refunded', function () {
    $reservation = makeReservation($this->owner, now()->addDays(2), now()->addDays(2)->addHour(), ['status' => 'pending']);

    Livewire::test(ListReservations::class)
        ->assertTableActionExists('cancel', fn (Action $action) => str_contains((string) $action->getModalDescription(), 'not been paid for'), $reservation);
});

test('cancelling one that has already started says there is no refund', function () {
    $reservation = makeReservation($this->owner, now()->subMinutes(10), now()->addMinutes(50));

    Livewire::test(ListReservations::class)
        ->assertTableActionExists('cancel', fn (Action $action) => str_contains((string) $action->getModalDescription(), 'without a refund'), $reservation);
});
