<?php

namespace App\Policies;

use App\Models\ConversationParticipant;
use App\Models\Message;
use App\Models\User;
use Illuminate\Support\Carbon;

class MessagePolicy
{
    /** Only author can update within 5 minutes and must be a participant. */
    public function update(User $user, Message $message): bool
    {
        if ($message->user_id !== $user->id) {
            return false;
        }

        $isParticipant = ConversationParticipant::query()
            ->where('conversation_id', $message->conversation_id)
            ->where('user_id', $user->id)
            ->exists();
        if (!$isParticipant) {
            return false;
        }

        return $message->created_at->gt(now()->subMinutes(5));
    }

    /** Author can delete within 5 minutes; admins could extend later. */
    public function delete(User $user, Message $message): bool
    {
        return $this->update($user, $message);
    }
}
