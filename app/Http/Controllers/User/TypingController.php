<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\ConversationParticipant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Events\TypingStarted;
use App\Events\TypingStopped;

class TypingController extends Controller
{
    public function start(Request $request, Conversation $conversation): JsonResponse
    {
        $user = $request->user();
        $isParticipant = ConversationParticipant::query()
            ->where('conversation_id', $conversation->id)
            ->where('user_id', $user->id)
            ->exists();
        if (!$isParticipant) {
            return response()->json(['message' => __('Forbidden')], 403);
        }

        // Broadcast typing started
        event(new TypingStarted($conversation->id, [
            'id' => $user->id,
            'name' => $user->name,
        ]));
        return response()->json(['message' => __('Typing started.')]);
    }

    public function stop(Request $request, Conversation $conversation): JsonResponse
    {
        $user = $request->user();
        $isParticipant = ConversationParticipant::query()
            ->where('conversation_id', $conversation->id)
            ->where('user_id', $user->id)
            ->exists();
        if (!$isParticipant) {
            return response()->json(['message' => __('Forbidden')], 403);
        }

        // Broadcast typing stopped
        event(new TypingStopped($conversation->id, [
            'id' => $user->id,
            'name' => $user->name,
        ]));
        return response()->json(['message' => __('Typing stopped.')]);
    }
}
