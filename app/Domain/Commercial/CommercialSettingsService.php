<?php

namespace App\Domain\Commercial;

use App\Domain\Tenancy\TenantContext;
use App\Models\CommercialSetting;
use App\Models\Organization;

class CommercialSettingsService
{
    public function forOrganization(Organization $organization): CommercialSetting
    {
        app(TenantContext::class)->set($organization);

        return CommercialSetting::query()->firstOrCreate(
            ['organization_id' => $organization->id],
            [
                'sales_inactivity_days' => 90,
                'relationship_inactivity_days' => 30,
                'reactivation_repeat_cooldown_days' => 60,
                'contact_frequency_days' => ['A' => 15, 'B' => 30, 'C' => 45, 'D' => 60],
                'scoring_weights' => ['volume' => 40, 'frequency' => 20, 'potential' => 20, 'relationship' => 20],
                'volume_score_bands' => [
                    ['max_amount' => '0.00', 'points' => 0],
                    ['max_amount' => '5000.00', 'points' => 8],
                    ['max_amount' => '25000.00', 'points' => 16],
                    ['max_amount' => '75000.00', 'points' => 24],
                    ['max_amount' => '200000.00', 'points' => 32],
                    ['max_amount' => null, 'points' => 40],
                ],
                'frequency_score_bands' => [
                    ['min_purchases' => 0, 'points' => 0],
                    ['min_purchases' => 1, 'points' => 5],
                    ['min_purchases' => 2, 'points' => 10],
                    ['min_purchases' => 3, 'points' => 15],
                    ['min_purchases' => 4, 'points' => 20],
                ],
                'tiers' => [
                    ['key' => 'D', 'min_score' => 0, 'max_score' => 24, 'color' => '#64748b'],
                    ['key' => 'C', 'min_score' => 25, 'max_score' => 49, 'color' => '#0ea5e9'],
                    ['key' => 'B', 'min_score' => 50, 'max_score' => 74, 'color' => '#8b5cf6'],
                    ['key' => 'A', 'min_score' => 75, 'max_score' => 100, 'color' => '#f59e0b'],
                ],
            ],
        );
    }
}
