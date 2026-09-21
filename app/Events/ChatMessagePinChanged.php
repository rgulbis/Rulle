<?php

namespace App\Events;

use App\Models\ChatMessage;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

class ChatMessagePinChanged implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(public ChatMessage $message) {}

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('chat')];
    }

    public function broadcastAs(): string
    {
        return 'message.pin-changed';
    }

    /**
     * The whole message is sent (not just an id) since a pinned message can
     * be older than the recent window other clients loaded.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'pinned' => $this->message->pinned_at !== null,
            'message' => $this->message->toClientArray(),
        ];
    }
}
