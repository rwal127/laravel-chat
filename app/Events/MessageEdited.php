<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MessageEdited implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $conversationId,
        public array $message
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('conversations.' . $this->conversationId)];
    }

    public function broadcastAs(): string
    {
        return 'message.edited';
    }

    public function broadcastWith(): array
    {
        return $this->message;
    }
}
