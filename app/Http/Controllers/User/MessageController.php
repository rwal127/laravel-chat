<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Http\Requests\Messages\MessageStoreRequest;
use App\Http\Requests\Messages\MessageUpdateRequest;
use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\Message;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MessageController extends Controller
{
    /**
     * List messages in a conversation with pagination (oldest first for easy append).
     */
    public function index(Request $request, Conversation $conversation): JsonResponse
    {
        $user = $request->user();
        $perPage = (int) $request->integer('per_page', 30);

        $isParticipant = ConversationParticipant::query()
            ->where('conversation_id', $conversation->id)
            ->where('user_id', $user->id)
            ->exists();
        if (!$isParticipant) {
            return response()->json(['message' => __('Forbidden')], 403);
        }

        $paginator = Message::query()
            ->where('conversation_id', $conversation->id)
            ->with('user:id,name,avatar')
            ->orderBy('created_at', 'asc')
            ->paginate($perPage);

        $data = $paginator->getCollection()->map(function (Message $m) {
            return [
                'id' => $m->id,
                'body' => $m->body,
                'user' => [
                    'id' => $m->user->id,
                    'name' => $m->user->name,
                    'avatar_url' => $m->user->avatar_url ?? null,
                ],
                'created_at' => $m->created_at,
                'edited_at' => $m->edited_at,
                'deleted_at' => $m->deleted_at,
            ];
        });

        return response()->json([
            'data' => $data,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    /**
     * Send a message to a conversation.
     */
    public function store(MessageStoreRequest $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validated();

        $conversation = Conversation::findOrFail($data['conversation_id']);

        $isParticipant = ConversationParticipant::query()
            ->where('conversation_id', $conversation->id)
            ->where('user_id', $user->id)
            ->exists();
        if (!$isParticipant) {
            return response()->json(['message' => __('Forbidden')], 403);
        }

        if (!($data['body'] ?? null)) {
            return response()->json(['message' => __('Message body is required when there are no attachments.')], 422);
        }

        $message = DB::transaction(function () use ($conversation, $user, $data) {
            $msg = Message::create([
                'conversation_id' => $conversation->id,
                'user_id' => $user->id,
                'body' => $data['body'],
            ]);

            // Touch conversation and set last message id
            $conversation->update([
                'last_message_id' => $msg->id,
                'updated_at' => now(),
            ]);

            // Optionally set sender last_read_at to now
            ConversationParticipant::query()
                ->where('conversation_id', $conversation->id)
                ->where('user_id', $user->id)
                ->update(['last_read_at' => now()]);

            return $msg;
        });

        return response()->json([
            'id' => $message->id,
            'created_at' => $message->created_at,
        ], 201);
    }

    /**
     * Update a message body within the allowed window.
     */
    public function update(MessageUpdateRequest $request, Message $message): JsonResponse
    {
        $this->authorize('update', $message);

        $data = $request->validated();
        $message->update([
            'body' => $data['body'],
            'edited_at' => now(),
        ]);

        return response()->json([
            'id' => $message->id,
            'edited_at' => $message->edited_at,
        ]);
    }

    /**
     * Soft delete a message within the allowed window.
     */
    public function destroy(Request $request, Message $message): JsonResponse
    {
        $this->authorize('delete', $message);

        $message->delete();
        return response()->json(['message' => __('Message deleted.')]);
    }
}
