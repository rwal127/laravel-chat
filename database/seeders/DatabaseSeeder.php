<?php

namespace Database\Seeders;

use App\Models\User;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $usersJsonPath = base_path('private/users.json');
        if (!file_exists($usersJsonPath)) {
            $this->command->error('private/users.json not found!');
            return;
        }

        $users = json_decode(file_get_contents($usersJsonPath), true);

        foreach ($users as $userData) {
            User::updateOrCreate(
                ['email' => $userData['email']], // Find user by email
                [
                    'name' => $userData['name'],
                    'password' => $userData['password'], // The User model will hash this automatically
                ]
            );
        }

        User::factory(30)->create();
    }
}
