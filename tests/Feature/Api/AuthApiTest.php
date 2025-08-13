<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\Fluent\AssertableJson;

use function Pest\Laravel\postJson;
use function Pest\Laravel\getJson;

it('logs in and returns bearer token, me works, then logout revokes token', function () {
    $password = 'secret-password';
    /** @var User $user */
    $user = User::factory()->create([
        'password' => Hash::make($password),
    ]);

    // Login
    $login = postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => $password,
        'device_name' => 'pest',
    ], [ 'Accept' => 'application/json' ])
      ->assertCreated()
      ->assertJson(fn (AssertableJson $json) => $json
          ->hasAll(['access_token', 'token_type', 'user'])
          ->where('token_type', 'Bearer')
      )
      ->json();

    $token = $login['access_token'];

    // Me
    getJson('/api/v1/auth/me', [
        'Authorization' => 'Bearer '.$token,
        'Accept' => 'application/json',
    ])->assertOk()->assertJson(fn (AssertableJson $json) => $json
        ->where('id', $user->id)
        ->where('email', $user->email)
        ->etc()
    );

    // Logout
    postJson('/api/v1/auth/logout', [], [
        'Authorization' => 'Bearer '.$token,
        'Accept' => 'application/json',
    ])->assertOk();

    // Token should be revoked
    getJson('/api/v1/auth/me', [
        'Authorization' => 'Bearer '.$token,
        'Accept' => 'application/json',
    ])->assertUnauthorized();
});
