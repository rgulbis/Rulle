<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Entry QR code lifetime
    |--------------------------------------------------------------------------
    |
    | The QR code on a customer's dashboard is a signed token that stops
    | working this many seconds after it was issued, and works for one
    | successful scan only. The dashboard asks for a fresh one well before
    | this runs out, so a screenshot is worthless within a minute.
    |
    */

    'token_ttl_seconds' => (int) env('CHECKIN_TOKEN_TTL_SECONDS', 60),

    /*
    |--------------------------------------------------------------------------
    | Longest plausible visit
    |--------------------------------------------------------------------------
    |
    | Someone who checked in and was never scanned out (left through the gate,
    | phone died) stops counting as inside after this many minutes, and the
    | checkins:close-stale job writes the missing check-out. Park closing time
    | closes people out sooner than this on a normal day; this is the backstop.
    |
    */

    'max_visit_minutes' => (int) env('CHECKIN_MAX_VISIT_MINUTES', 720),

];
