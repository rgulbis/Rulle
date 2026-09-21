<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

class ChatSlowModeActivated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(public int $remainingSeconds, public int $cooldownSeconds) {}

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('chat')];
    }

    public function broadcastAs(): string
    {
        return 'slow-mode.activated';
    }

    /**
     * Durations rather than an absolute end time, so a client with a skewed
     * clock still counts down the right amount.
     *
     * @return array<string, int>
     */
    public function broadcastWith(): array
    {
        return [
            'remaining_seconds' => $this->remainingSeconds,
            'cooldown_seconds' => $this->cooldownSeconds,
        ];
    }
}
