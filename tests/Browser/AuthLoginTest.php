<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Laravel\Dusk\Browser;

it('allows admin to login via browser', function () {
    // Arrange: ensure the admin user exists
    $user = User::query()->firstOrCreate(
        ['email' => 'admin@test-chat.dev'],
        [
            'name' => 'Administrator',
            'password' => Hash::make('administrator'),
        ]
    );

    // Act & Assert: visit login, submit credentials, land on chat
    $this->browse(function (Browser $browser) use ($user) {
        $browser
            // Ensure we are logged out to avoid RedirectIfAuthenticated
            ->visit('/logout')
            ->visit('/login')
            ->waitFor('input[name=email]', 5)
            ->type('email', $user->email)
            ->type('password', 'administrator')
            ->click('@login-button')
            ->waitForLocation('/chat', 5)
            ->assertPathIs('/chat')
            ->assertSee('Chat');
    });
});
