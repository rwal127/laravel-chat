<?php

namespace App\Policies;

use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\User;

class ConversationPolicy
{
    public function view(User $user, Conversation $conversation): bool
    {
        return ConversationParticipant::query()
            ->where('conversation_id', $conversation->id)
            ->where('user_id', $user->id)
            ->exists();
    }

    public function send(User $user, Conversation $conversation): bool
    {
        // Same as view for now; extend later if needed
        return $this->view($user, $conversation);
    }
}
