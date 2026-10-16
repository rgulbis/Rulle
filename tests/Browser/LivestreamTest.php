<?php

use App\Models\CheckInEvent;
use App\Models\User;

test('a guest sees the livestream and park occupancy', function () {
    User::factory()->count(2)->create()->each(
        fn (User $rider) => CheckInEvent::create(['user_id' => $rider->id, 'checked_in' => true]),
    );

    visit('/livestream')
        ->assertSee('Tiešraide')
        ->assertSee('Kameras signāls pašlaik nav pieejams')
        ->assertSee('Parkā šobrīd')
        ->assertSee('Parkā ir 2 cilvēki')
        ->assertSee('Šodien nav rezervāciju.')
        ->assertSee('Izskatās labi? Nāciet braukt.')
        ->assertNoJavaScriptErrors();
});
