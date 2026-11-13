<?php

namespace Database\Seeders;

use App\Domain\Journeys\JourneyService;
use App\Domain\Tenancy\TenantContext;
use App\Models\Organization;
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

        $onboardingOwner = User::query()->firstOrCreate(
            ['email' => 'onboarding-owner@example.test'],
            [
                'name' => 'Responsável pelo onboarding E2E',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
            ],
        );
        $onboardingOrganization = Organization::query()->firstOrCreate(
            ['name' => 'Workspace Onboarding E2E'],
            [
                'timezone' => 'America/Sao_Paulo',
                'onboarding_step' => 'organization',
            ],
        );
        $onboardingOrganization->users()->syncWithoutDetaching([
            $onboardingOwner->id => ['role' => 'owner', 'is_active' => true],
        ]);
        app(TenantContext::class)->set($onboardingOrganization);
        app(JourneyService::class)->ensureDefaultTemplate($onboardingOrganization);
    }
}
