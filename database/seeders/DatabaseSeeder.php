<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // User::factory(10)->create();

        User::firstOrNew([
            'name' => 'Super Admin',
            'email' => 'superadmin@m9.com',
            'password' => Hash::make('1q2w3e4r5t'),
            'email_verified_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ])->save();

        $this->call([
            ShieldSeeder::class,
        ]);
    }
}
