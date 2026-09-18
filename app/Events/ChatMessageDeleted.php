<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

class ChatMessageDeleted implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(public int $messageId, public ?int $reservationId = null) {}

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        $channel = $this->reservationId
            ? "reservation.{$this->reservationId}.chat"
            : 'chat';

        return [new PrivateChannel($channel)];
    }

    public function broadcastAs(): string
    {
        return 'message.deleted';
    }

    /**
     * @return array<string, int>
     */
    public function broadcastWith(): array
    {
        return ['id' => $this->messageId];
    }
}
