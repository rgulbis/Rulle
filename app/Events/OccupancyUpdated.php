<?php

namespace App\Events;

use App\Models\User;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

// A plain (public) Channel, not PrivateChannel — the livestream page is
// public too, and a headcount isn't sensitive the way who's inside is.
class OccupancyUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [new Channel('occupancy')];
    }

    public function broadcastAs(): string
    {
        return 'occupancy.updated';
    }

    /**
     * @return array<string, int>
     */
    public function broadcastWith(): array
    {
        return ['count' => User::where('checked_in', true)->count()];
    }
}
