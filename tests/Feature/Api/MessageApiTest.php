<?php

declare(strict_types=1);

use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\Fluent\AssertableJson;
use Laravel\Sanctum\Sanctum;

use function Pest\Laravel\postJson;
use function Pest\Laravel\getJson;
use function Pest\Laravel\patchJson;
use function Pest\Laravel\deleteJson;

// We use Sanctum::actingAs to avoid stateful cookie/session interference between tests

it('allows a participant to send and list messages with pagination', function () {
    $alice = User::factory()->create();
    $bob = User::factory()->create();

    $conversation = Conversation::factory()->create();
    ConversationParticipant::factory()->create(['conversation_id' => $conversation->id, 'user_id' => $alice->id]);
    ConversationParticipant::factory()->create(['conversation_id' => $conversation->id, 'user_id' => $bob->id]);

    // Authenticate as Alice for message creation and listing
    Sanctum::actingAs($alice, ['*']);

    // Send three messages
    foreach (["m1","m2","m3"] as $body) {
        postJson('/api/v1/messages', [
            'conversation_id' => $conversation->id,
            'body' => $body,
        ])
            ->assertCreated()
            ->assertJson(fn (AssertableJson $json) => $json->hasAll(['id','created_at']));
    }

    // List messages, oldest-first chunk of size 2 (controller returns last N reversed)
    $resp = getJson('/api/v1/conversations/'.$conversation->id.'/messages?per_page=2')
        ->assertOk()
        ->assertJson(fn (AssertableJson $json) => $json
            ->has('data', 2)
            ->has('meta', fn ($m) => $m->where('per_page', 2)->where('has_more', true)->etc())
        )->json();

    // Expect the page to contain m2 and m3 (order may vary based on DB specifics)
    $pageBodies = array_map(fn($r) => $r['body'], $resp['data']);
    expect($pageBodies)->toContain('m2');
    expect($pageBodies)->toContain('m3');

    // Use next_before_id to get older chunk (which should yield m1 as the remaining item)
    $before = $resp['meta']['next_before_id'];
    getJson('/api/v1/conversations/'.$conversation->id.'/messages?per_page=2&before_id='.$before)
        ->assertOk()
        ->assertJson(fn (AssertableJson $json) => $json
            ->has('data', 1)
            ->where('meta.has_more', false)
        );
});

it('enforces update policy: author within 5 minutes only', function () {
    $author = User::factory()->create();
    $other = User::factory()->create();
    $conversation = Conversation::factory()->create();
    ConversationParticipant::factory()->create(['conversation_id' => $conversation->id, 'user_id' => $author->id]);
    ConversationParticipant::factory()->create(['conversation_id' => $conversation->id, 'user_id' => $other->id]);

    // Author sends message
    Sanctum::actingAs($author, ['*']);
    $msgId = postJson('/api/v1/messages', [
        'conversation_id' => $conversation->id,
        'body' => 'original',
    ])->assertCreated()->json('id');

    // Other cannot edit
    Sanctum::actingAs($other, ['*']);
    patchJson('/api/v1/messages/'.$msgId, [ 'body' => 'hacked' ])
        ->assertForbidden();

    // Author can edit within 5 minutes
    Sanctum::actingAs($author, ['*']);
    patchJson('/api/v1/messages/'.$msgId, [ 'body' => 'edited' ])
        ->assertOk()
        ->assertJson(fn (AssertableJson $json) => $json->hasAll(['id','edited_at']));

    // Travel beyond 5 minutes then try to edit again -> forbidden
    test()->travel(6)->minutes();
    patchJson('/api/v1/messages/'.$msgId, [ 'body' => 'late' ])
        ->assertForbidden();
});

it('enforces delete policy: author within 5 minutes only', function () {
    $author = User::factory()->create();
    $other = User::factory()->create();
    $conversation = Conversation::factory()->create();
    ConversationParticipant::factory()->create(['conversation_id' => $conversation->id, 'user_id' => $author->id]);
    ConversationParticipant::factory()->create(['conversation_id' => $conversation->id, 'user_id' => $other->id]);

    Sanctum::actingAs($author, ['*']);
    $msgId = postJson('/api/v1/messages', [
        'conversation_id' => $conversation->id,
        'body' => 'to-delete',
    ])->assertCreated()->json('id');

    // Other cannot delete
    Sanctum::actingAs($other, ['*']);
    deleteJson('/api/v1/messages/'.$msgId)
        ->assertForbidden();

    // Author can delete within 5 minutes
    Sanctum::actingAs($author, ['*']);
    deleteJson('/api/v1/messages/'.$msgId)
        ->assertOk();

    // Another message and travel beyond 5 minutes: then cannot delete
    $msgId2 = postJson('/api/v1/messages', [
        'conversation_id' => $conversation->id,
        'body' => 'late-delete',
    ])->assertCreated()->json('id');

    test()->travel(6)->minutes();
    deleteJson('/api/v1/messages/'.$msgId2)
        ->assertForbidden();
});

it('protects attachments via policy (participants only)', function () {
    Storage::fake('public');

    $alice = User::factory()->create();
    $charlie = User::factory()->create();
    $conversation = Conversation::factory()->create();
    ConversationParticipant::factory()->create(['conversation_id' => $conversation->id, 'user_id' => $alice->id]);

    // Alice sends an attachment-only message via messages.store with multipart
    Sanctum::actingAs($alice, ['*']);
    $file = UploadedFile::fake()->create('doc.txt', 1, 'text/plain');
    $resp = postJson('/api/v1/messages', [
        'conversation_id' => $conversation->id,
        'attachments' => [$file],
    ], ['Accept' => 'application/json'])
        ->assertCreated();

    // Find the attachment via DB
    $message = Message::query()->findOrFail($resp->json('id'));
    $attachment = MessageAttachment::query()->where('message_id', $message->id)->firstOrFail();

    // Non-participant cannot access inline or download
    Sanctum::actingAs($charlie, ['*']);
    getJson('/api/v1/attachments/'.$attachment->id.'/inline')
        ->assertForbidden();
    getJson('/api/v1/attachments/'.$attachment->id.'/download')
        ->assertForbidden();

    // Participant can access
    Sanctum::actingAs($alice, ['*']);
    getJson('/api/v1/attachments/'.$attachment->id.'/inline')
        ->assertOk();
    getJson('/api/v1/attachments/'.$attachment->id.'/download')
        ->assertOk();
});
