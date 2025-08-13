<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class ContactAdded implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $recipientUserId,
        public array $contact // minimal contact payload to add
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('users.' . $this->recipientUserId)];
    }

    public function broadcastAs(): string
    {
        return 'contact.added';
    }

    public function broadcastWith(): array
    {
        return $this->contact;
    }
}
