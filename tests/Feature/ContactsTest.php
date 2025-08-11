<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContactsTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_add_contact(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/contacts', ['contact_user_id' => $other->id])
            ->assertCreated();

        $this->actingAs($user)
            ->getJson('/contacts')
            ->assertOk()
            ->assertJsonFragment(['id' => $other->id]);
    }

    public function test_user_cannot_add_self_or_duplicate(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/contacts', ['contact_user_id' => $user->id])
            ->assertUnprocessable();

        $this->actingAs($user)
            ->postJson('/contacts', ['contact_user_id' => $other->id])
            ->assertCreated();

        $this->actingAs($user)
            ->postJson('/contacts', ['contact_user_id' => $other->id])
            ->assertUnprocessable();
    }
}
