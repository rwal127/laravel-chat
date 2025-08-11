<?php

use Illuminate\Support\Facades\Broadcast;
use App\Models\ConversationParticipant;

Broadcast::channel('conversations.{conversationId}', function ($user, int $conversationId) {
    // Authorize only participants of the conversation
    return ConversationParticipant::query()
        ->where('conversation_id', $conversationId)
        ->where('user_id', $user->id)
        ->exists();
});
