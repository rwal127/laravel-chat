<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Conversation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Conversation>
 */
class ConversationFactory extends Factory
{
    protected $model = Conversation::class;

    public function definition(): array
    {
        return [
            'type' => $this->faker->randomElement(['direct','group']),
            'name' => $this->faker->boolean(30) ? $this->faker->sentence(3) : null,
            'last_message_id' => null,
        ];
    }
}
