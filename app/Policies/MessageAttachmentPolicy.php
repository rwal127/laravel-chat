<?php
declare(strict_types=1);

namespace App\Policies;

use App\Models\ConversationParticipant;
use App\Models\MessageAttachment;
use App\Models\User;

final class MessageAttachmentPolicy
{
    /**
     * Determine whether the user can view/download the attachment.
     */
    public function view(User $user, MessageAttachment $attachment): bool
    {
        $message = $attachment->message;
        if (!$message) {
            return false;
        }

        return ConversationParticipant::query()
            ->where('conversation_id', $message->conversation_id)
            ->where('user_id', $user->id)
            ->exists();
    }
}
