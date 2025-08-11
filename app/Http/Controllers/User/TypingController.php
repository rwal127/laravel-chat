<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\ConversationParticipant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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

        // TODO: Broadcast typing started event via websockets (e.g., Laravel Echo)
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

        // TODO: Broadcast typing stopped event via websockets
        return response()->json(['message' => __('Typing stopped.')]);
    }
}
