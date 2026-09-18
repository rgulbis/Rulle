<?php

use App\Models\Reservation;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

// One global chat room — any authenticated user (customer, employee, or
// admin) can listen; reaching this callback at all already implies a valid
// session, so there's nothing further to check.
Broadcast::channel('chat', function ($user) {
    return $user !== null;
});

// A reservation's own private group chat — only its owner and named
// participants can listen in.
Broadcast::channel('reservation.{reservationId}.chat', function ($user, $reservationId) {
    $reservation = Reservation::whereKey($reservationId)->first();

    return $reservation !== null && $reservation->includesParticipant($user);
});
