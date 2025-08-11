<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TestPusherPing implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $message;
    public string $time;

    public function __construct(string $message = 'ping')
    {
        $this->message = $message;
        $this->time = now()->toISOString();
    }

    public function broadcastOn(): Channel
    {
        return new Channel('public.test');
    }

    public function broadcastAs(): string
    {
        return 'test.ping';
    }

    public function broadcastWith(): array
    {
        return [
            'message' => $this->message,
            'time' => $this->time,
        ];
    }
}
