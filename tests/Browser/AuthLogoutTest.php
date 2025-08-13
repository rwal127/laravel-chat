<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Laravel\Dusk\Browser;

it('allows user to logout via browser', function () {
    // Arrange: ensure the admin user exists
    $user = User::query()->firstOrCreate(
        ['email' => 'admin@test-chat.dev'],
        [
            'name' => 'Administrator',
            'password' => Hash::make('administrator'),
        ]
    );

    $this->browse(function (Browser $browser) use ($user) {
        // Login first
        $browser
            ->visit('/login')
            ->waitFor('input[name=email]', 5)
            ->type('email', $user->email)
            ->type('password', 'administrator')
            ->click('@login-button')
            ->waitForLocation('/chat', 5)
            ->assertPathIs('/chat');

        // Logout using robust selectors
        $browser
            ->click('@user-menu-trigger')
            ->waitFor('@logout-link', 3)
            ->click('@logout-link')
            ->waitForLocation('/', 5)
            ->assertPathIs('/')
            ->assertSee('Laravel');
    });
});
