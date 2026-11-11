<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Company> */
class CompanyFactory extends Factory
{
    protected $model = Company::class;

    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'name' => fake()->unique()->company(),
            'trade_name' => null,
            'legal_name' => null,
            'tax_id' => null,
            'segment' => fake()->randomElement(['Arquitetura', 'Construção', 'Distribuição']),
            'relationship_status' => 'prospect',
            'owner_user_id' => null,
        ];
    }

    public function ownedBy(User $user): static
    {
        return $this->state(fn (): array => ['owner_user_id' => $user->id]);
    }
}
