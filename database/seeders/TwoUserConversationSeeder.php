<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\Message;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class TwoUserConversationSeeder extends Seeder
{
    /**
     * Seed a direct conversation between user id 1 and user id 2 with 250 messages.
     */
    public function run(): void
    {
        $u1 = User::query()->find(1);
        $u2 = User::query()->find(2);
        if (!$u1 || !$u2) {
            $this->command?->warn('User 1 or 2 not found. Skipping TwoUserConversationSeeder.');
            return;
        }

        DB::transaction(function () use ($u1, $u2): void {
            // Find existing direct conversation between the two, or create one
            $conversation = Conversation::query()
                ->where('type', 'direct')
                ->whereExists(function ($q) use ($u1) {
                    $q->select(DB::raw('1'))
                      ->from('conversation_participants as cp1')
                      ->whereColumn('cp1.conversation_id', 'conversations.id')
                      ->where('cp1.user_id', $u1->id);
                })
                ->whereExists(function ($q) use ($u2) {
                    $q->select(DB::raw('1'))
                      ->from('conversation_participants as cp2')
                      ->whereColumn('cp2.conversation_id', 'conversations.id')
                      ->where('cp2.user_id', $u2->id);
                })
                ->first();

            if (!$conversation) {
                $conversation = Conversation::create(['type' => 'direct']);
                ConversationParticipant::firstOrCreate(['conversation_id' => $conversation->id, 'user_id' => $u1->id], ['role' => 'member']);
                ConversationParticipant::firstOrCreate(['conversation_id' => $conversation->id, 'user_id' => $u2->id], ['role' => 'member']);
            }

            // Generate 250 alternating messages with Faker bodies
            $start = Carbon::now()->subMinutes(250);
            $messages = [];
            for ($i = 0; $i < 250; $i++) {
                $authorId = ($i % 2 === 0) ? $u1->id : $u2->id;
                $created = (clone $start)->addMinutes($i);
                $messages[] = [
                    'conversation_id' => $conversation->id,
                    'user_id' => $authorId,
                    'body' => fake()->sentences(mt_rand(1, 3), true),
                    'created_at' => $created,
                    'updated_at' => $created,
                ];
            }

            // Insert messages in chunks
            foreach (array_chunk($messages, 100) as $chunk) {
                Message::query()->insert($chunk);
            }

            // Set last_message_id and updated_at on conversation
            $lastMessage = Message::query()
                ->where('conversation_id', $conversation->id)
                ->latest('id')
                ->first();

            if ($lastMessage) {
                $conversation->update([
                    'last_message_id' => $lastMessage->id,
                    'updated_at' => $lastMessage->created_at,
                ]);

                // Optionally set last_read_at for both participants to the latest time
                ConversationParticipant::query()
                    ->where('conversation_id', $conversation->id)
                    ->whereIn('user_id', [$u1->id, $u2->id])
                    ->update(['last_read_at' => $lastMessage->created_at]);
            }
        });
    }
}
