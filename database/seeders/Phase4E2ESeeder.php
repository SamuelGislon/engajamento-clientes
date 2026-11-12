<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

class Phase4E2ESeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('testing')) {
            throw new RuntimeException('The Phase 4 E2E seeder only runs in testing.');
        }

        $this->call(DemoSeeder::class);

        User::query()->firstOrCreate(
            ['email' => 'phase4-invitee@example.test'],
            [
                'name' => 'Vendedor Convidado E2E',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
            ],
        );
    }
}
