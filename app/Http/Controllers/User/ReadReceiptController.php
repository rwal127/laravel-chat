<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\Message;
use App\Models\MessageReceipt;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Events\ReadUpdated;

class ReadReceiptController extends Controller
{
    /**
     * Mark all messages up to provided message_id as read for the current user in the conversation.
     */
    public function read(Request $request, Conversation $conversation): JsonResponse
    {
        $user = $request->user();

        $request->validate([
            'message_id' => ['required', 'integer', 'exists:messages,id'],
        ]);
        $maxMessageId = (int) $request->integer('message_id');

        $isParticipant = ConversationParticipant::query()
            ->where('conversation_id', $conversation->id)
            ->where('user_id', $user->id)
            ->exists();
        if (!$isParticipant) {
            return response()->json(['message' => __('Forbidden')], 403);
        }

        DB::transaction(function () use ($conversation, $user, $maxMessageId) {
            // Select message ids authored by others in this conversation up to the provided id
            $ids = Message::query()
                ->where('conversation_id', $conversation->id)
                ->where('id', '<=', $maxMessageId)
                ->where('user_id', '!=', $user->id)
                ->pluck('id');

            if ($ids->isNotEmpty()) {
                $now = now();
                $rows = $ids->map(fn ($id) => [
                    'message_id' => $id,
                    'user_id' => $user->id,
                    'status' => 'read',
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all();

                // Upsert on unique (message_id, user_id)
                MessageReceipt::query()->upsert($rows, ['message_id', 'user_id'], ['status', 'updated_at']);
            }

            ConversationParticipant::query()
                ->where('conversation_id', $conversation->id)
                ->where('user_id', $user->id)
                ->update(['last_read_at' => now()]);
        });

        // Broadcast read updated for the max message id acknowledged
        event(new ReadUpdated($conversation->id, $user->id, $maxMessageId));

        return response()->json(['message' => __('Read receipts updated.')]);
    }
}
