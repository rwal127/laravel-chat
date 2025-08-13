<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ConversationParticipant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ConversationParticipant>
 */
class ConversationParticipantFactory extends Factory
{
    protected $model = ConversationParticipant::class;

    public function definition(): array
    {
        return [
            'conversation_id' => null,
            'user_id' => null,
            'role' => 'member',
            'last_read_at' => null,
            'joined_at' => now(),
        ];
    }
}
