<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Queue\SerializesModels;

class ReadUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $conversationId,
        public int $userId,
        public int $messageId
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('conversations.' . $this->conversationId)];
    }

    public function broadcastAs(): string
    {
        return 'read.updated';
    }

    public function broadcastWith(): array
    {
        return [
            'user_id' => $this->userId,
            'message_id' => $this->messageId,
        ];
    }
}
