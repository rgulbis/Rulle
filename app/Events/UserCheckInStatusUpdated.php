<?php

namespace App\Events;

use App\Models\User;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

// Broadcast immediately rather than queueing: production only runs
// `php artisan serve`, with no queue worker to process a queued broadcast.
class UserCheckInStatusUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(public User $user) {}

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('App.Models.User.'.$this->user->id)];
    }

    public function broadcastAs(): string
    {
        return 'check-in.updated';
    }

    /**
     * @return array<string, bool>
     */
    public function broadcastWith(): array
    {
        return ['checked_in' => $this->user->checked_in];
    }
}
