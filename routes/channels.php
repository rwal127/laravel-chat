<?php

use Illuminate\Support\Facades\Broadcast;
use App\Models\ConversationParticipant;

Broadcast::channel('conversations.{conversationId}', function ($user, int $conversationId) {
    // Authorize only participants of the conversation
    $isParticipant = ConversationParticipant::query()
        ->where('conversation_id', $conversationId)
        ->where('user_id', $user->id)
        ->exists();
    if (!$isParticipant) {
        return false;
    }
    // For presence channels, return user info hash; for private channels, non-false authorizes
    return [
        'id' => $user->id,
        'name' => $user->name,
        'avatar_url' => $user->avatar_url ?? null,
    ];
});
 
// Personal user channel for per-user notifications (e.g., contact.added)
Broadcast::channel('users.{userId}', function ($user, int $userId) {
    return (int) $user->id === (int) $userId
        ? [
            'id' => $user->id,
            'name' => $user->name,
            'avatar_url' => $user->avatar_url ?? null,
        ]
        : false;
});
