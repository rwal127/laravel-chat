<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Http\Requests\Attachments\AttachmentStoreRequest;
use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\Message;
use App\Models\MessageAttachment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class AttachmentController extends Controller
{
    /**
     * Upload a single attachment to a conversation by creating a message with attachment.
     */
    public function store(AttachmentStoreRequest $request, Conversation $conversation): JsonResponse
    {
        $user = $request->user();

        $isParticipant = ConversationParticipant::query()
            ->where('conversation_id', $conversation->id)
            ->where('user_id', $user->id)
            ->exists();
        if (!$isParticipant) {
            return response()->json(['message' => __('Forbidden')], 403);
        }

        $file = $request->file('file');
        $path = $file->store('attachments', 'public');

        $message = DB::transaction(function () use ($conversation, $user, $file, $path) {
            $msg = Message::create([
                'conversation_id' => $conversation->id,
                'user_id' => $user->id,
                'body' => null,
                'has_attachments' => true,
            ]);

            MessageAttachment::create([
                'message_id' => $msg->id,
                'disk' => 'public',
                'path' => $path,
                'original_name' => $file->getClientOriginalName(),
                'mime_type' => $file->getClientMimeType(),
                'size_bytes' => $file->getSize(),
            ]);

            $conversation->update([
                'last_message_id' => $msg->id,
                'updated_at' => now(),
            ]);

            ConversationParticipant::query()
                ->where('conversation_id', $conversation->id)
                ->where('user_id', $user->id)
                ->update(['last_read_at' => now()]);

            return $msg;
        });

        // Build attachments payload
        $message->load('attachments');
        $attachments = $message->attachments->map(function (MessageAttachment $a) {
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

        // Broadcast message sent with attachments
        event(new \App\Events\MessageSent($conversation->id, [
            'id' => $message->id,
            'body' => null,
            'has_attachments' => true,
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
            'body' => null,
            'has_attachments' => true,
            'attachments' => $attachments,
            'created_at' => $message->created_at,
        ], 201);
    }

    /**
     * Download an attachment if the user participates in the conversation.
     */
    public function download(Request $request, MessageAttachment $attachment)
    {
        $this->authorize('view', $attachment);
        return Storage::disk($attachment->disk)
            ->download($attachment->path, $attachment->original_name, [
                'Content-Type' => $attachment->mime_type ?: 'application/octet-stream',
            ]);
    }

    /**
     * Inline stream an attachment (e.g., images) with policy enforcement.
     */
    public function inline(Request $request, MessageAttachment $attachment)
    {
        $this->authorize('view', $attachment);
        $headers = [
            'Content-Type' => $attachment->mime_type ?: 'application/octet-stream',
            'Content-Disposition' => 'inline; filename="'. addslashes($attachment->original_name) .'"',
        ];
        return Storage::disk($attachment->disk)->response($attachment->path, $attachment->original_name, $headers);
    }
}
