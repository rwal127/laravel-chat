<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Http\Requests\Messages\MessageStoreRequest;
use App\Http\Requests\Messages\MessageUpdateRequest;
use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\Message;
use App\Models\MessageAttachment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Events\MessageSent;
use App\Events\MessageEdited;
use App\Events\MessageDeleted;
use App\Models\MessageReceipt;

class MessageController extends Controller
{
    /**
     * List messages in a conversation with pagination (oldest first for easy append).
     */
    public function index(Request $request, Conversation $conversation): JsonResponse
    {
        $user = $request->user();
        $perPage = max(1, min(100, (int) $request->integer('per_page', 30)));
        $beforeId = $request->integer('before_id');
        $search = trim((string) $request->get('search', ''));

        $isParticipant = ConversationParticipant::query()
            ->where('conversation_id', $conversation->id)
            ->where('user_id', $user->id)
            ->exists();
        if (!$isParticipant) {
            return response()->json(['message' => __('Forbidden')], 403);
        }

        // Determine other participant for direct chats (null for groups)
        $otherUserId = ConversationParticipant::query()
            ->where('conversation_id', $conversation->id)
            ->where('user_id', '!=', $user->id)
            ->value('user_id');

        $query = Message::query()
            ->where('conversation_id', $conversation->id)
            ->with([
                'user:id,name,avatar',
                'attachments',
                // Preload receipts for both current and other user to avoid N+1
                'receipts' => function ($q) use ($user, $otherUserId) {
                    $ids = array_filter([$user->id, $otherUserId]);
                    if (!empty($ids)) {
                        $q->whereIn('user_id', $ids);
                    }
                }
            ]);

        if ($search !== '') {
            $query->where('body', 'like', "%{$search}%");
        }

        // Keyset pagination for infinite scroll up: fetch older than before_id if provided
        if ($beforeId) {
            $query->where('id', '<', $beforeId);
        }

        $items = $query->orderBy('id', 'desc')
            ->limit($perPage + 1)
            ->get();

        $hasMore = $items->count() > $perPage;
        $items = $items->take($perPage)->values()->reverse(); // return oldest-first chunk

        // Mark delivered for the current user for incoming messages that lack a receipt
        $incomingIds = $items->where('user_id', '!=', $user->id)->pluck('id');
        if ($incomingIds->isNotEmpty()) {
            $existing = MessageReceipt::query()
                ->whereIn('message_id', $incomingIds)
                ->where('user_id', $user->id)
                ->pluck('message_id')
                ->all();
            $now = now();
            $rows = [];
            foreach ($incomingIds as $mid) {
                if (!in_array($mid, $existing, true)) {
                    $rows[] = [
                        'message_id' => $mid,
                        'user_id' => $user->id,
                        'status' => 'delivered',
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
            }
            if (!empty($rows)) {
                MessageReceipt::insert($rows);
            }
        }

        $data = $items->map(function (Message $m) use ($user, $otherUserId) {
            // Compute delivered/read for sender's perspective in direct chats
            $deliveredAt = null;
            $readAt = null;
            if ($otherUserId && (int) $m->user_id === (int) $user->id) {
                $receiptRead = $m->receipts->firstWhere(function ($r) use ($otherUserId) {
                    return (int) $r->user_id === (int) $otherUserId && $r->status === 'read';
                });
                $receiptDelivered = $m->receipts->firstWhere(function ($r) use ($otherUserId) {
                    return (int) $r->user_id === (int) $otherUserId && in_array($r->status, ['delivered', 'read'], true);
                });
                if ($receiptDelivered) {
                    $deliveredAt = $receiptDelivered->created_at;
                }
                if ($receiptRead) {
                    $readAt = $receiptRead->updated_at ?: $receiptRead->created_at;
                }
            }

            $attachments = $m->attachments->map(function ($a) {
                $inlineUrl = route('attachments.inline', $a);
                $downloadUrl = route('attachments.download', $a);
                $isImage = str_starts_with((string) $a->mime_type, 'image/');
                return [
                    'id' => $a->id,
                    'url' => $inlineUrl,
                    'download_url' => $downloadUrl,
                    'mime_type' => $a->mime_type,
                    'original_name' => $a->original_name,
                    'size_bytes' => (int) $a->size_bytes,
                    'is_image' => $isImage,
                ];
            });

            return [
                'id' => $m->id,
                'body' => $m->body,
                'has_attachments' => (bool) $m->has_attachments,
                'attachments' => $attachments,
                'user' => [
                    'id' => $m->user->id,
                    'name' => $m->user->name,
                    'avatar_url' => $m->user->avatar_url ?? null,
                ],
                'created_at' => $m->created_at,
                'edited_at' => $m->edited_at,
                'deleted_at' => $m->deleted_at,
                'delivered_at' => $deliveredAt,
                'read_at' => $readAt,
            ];
        });

        return response()->json([
            'data' => $data,
            'meta' => [
                'per_page' => $perPage,
                'has_more' => $hasMore,
                'next_before_id' => $data->isNotEmpty() ? ($data->first()['id'] ?? null) : null,
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

        // Accept either body or attachments (validated in request). If both missing, reject.
        $hasUploaded = $request->hasFile('attachments');
        if (!($data['body'] ?? null) && !$hasUploaded) {
            return response()->json(['message' => __('Message body is required when there are no attachments.')], 422);
        }

        $message = DB::transaction(function () use ($conversation, $user, $data, $request) {
            $msg = Message::create([
                'conversation_id' => $conversation->id,
                'user_id' => $user->id,
                'body' => $data['body'] ?? null,
                'has_attachments' => $request->hasFile('attachments'),
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

            // Save attachments if provided
            if ($request->hasFile('attachments')) {
                foreach ($request->file('attachments') as $file) {
                    if (!$file) { continue; }
                    $path = $file->store('attachments', 'public');
                    MessageAttachment::create([
                        'message_id' => $msg->id,
                        'disk' => 'public',
                        'path' => $path,
                        'original_name' => $file->getClientOriginalName(),
                        'mime_type' => $file->getClientMimeType(),
                        'size_bytes' => $file->getSize(),
                    ]);
                }
            }

            return $msg;
        });

        // Load attachments for payload
        $message->load('attachments');
        $attachments = $message->attachments->map(function ($a) {
            $inlineUrl = route('attachments.inline', $a);
            $downloadUrl = route('attachments.download', $a);
            $isImage = str_starts_with((string) $a->mime_type, 'image/');
            return [
                'id' => $a->id,
                'url' => $inlineUrl,
                'download_url' => $downloadUrl,
                'mime_type' => $a->mime_type,
                'original_name' => $a->original_name,
                'size_bytes' => (int) $a->size_bytes,
                'is_image' => $isImage,
            ];
        })->values();

        // Broadcast message sent with possible attachments
        event(new MessageSent($conversation->id, [
            'id' => $message->id,
            'body' => $message->body,
            'has_attachments' => $attachments->isNotEmpty(),
            'attachments' => $attachments,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'avatar_url' => $user->avatar_url ?? null,
            ],
            'created_at' => $message->created_at,
        ]));

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

        // Broadcast message edited
        event(new MessageEdited($message->conversation_id, [
            'id' => $message->id,
            'body' => $message->body,
            'edited_at' => $message->edited_at,
        ]));

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
        // Broadcast message deleted
        event(new MessageDeleted($message->conversation_id, $message->id));
        return response()->json(['message' => __('Message deleted.')]);
    }
}
