<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Organization;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;

/**
 * @extends Factory<Sale>
 */
class SaleFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'company_id' => Company::factory()->state(fn (array $attributes): array => ['organization_id' => $attributes['organization_id']]),
            'seller_id' => User::factory(),
            'created_by' => fn (array $attributes): int => (int) $attributes['seller_id'],
            'description' => fake()->sentence(4),
            'amount' => fake()->randomFloat(2, 100, 10000),
            'currency' => 'BRL',
            'sold_at' => now()->subDays(fake()->numberBetween(1, 60)),
            'status' => 'confirmed',
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (Sale $sale): void {
            $now = now();
            $companyOrganizationId = DB::table('companies')
                ->where('id', $sale->company_id)
                ->value('organization_id');
            $sale->setAttribute('organization_id', $companyOrganizationId);

            $memberships = [
                [
                    'organization_id' => $companyOrganizationId,
                    'user_id' => $sale->seller_id,
                    'role' => 'salesperson',
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            ];

            if ($sale->created_by !== $sale->seller_id) {
                $memberships[] = [
                    'organization_id' => $companyOrganizationId,
                    'user_id' => $sale->created_by,
                    'role' => 'manager',
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            DB::table('organization_user')->insertOrIgnore($memberships);
            DB::table('companies')
                ->where('organization_id', $companyOrganizationId)
                ->where('id', $sale->company_id)
                ->whereNull('owner_user_id')
                ->update(['owner_user_id' => $sale->seller_id]);
        });
    }
}
