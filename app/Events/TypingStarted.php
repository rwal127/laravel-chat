<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Queue\SerializesModels;

class TypingStarted implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $conversationId,
        public array $user
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('conversations.' . $this->conversationId)];
    }

    public function broadcastAs(): string
    {
        return 'typing.started';
    }

    public function broadcastWith(): array
    {
        return ['user' => $this->user];
    }
}
