<?php

namespace Database\Factories;

use App\Models\Activity;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Activity> */
class ActivityFactory extends Factory
{
    protected $model = Activity::class;

    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'company_id' => Company::factory(),
            'contact_id' => null,
            'user_id' => User::factory(),
            'type' => 'call',
            'occurred_at' => now(),
            'description' => fake()->sentence(),
            'result' => null,
            'next_action' => null,
        ];
    }

    public function forCompany(Company $company, User $user, ?Contact $contact = null): static
    {
        return $this->state(fn (): array => [
            'organization_id' => $company->organization_id,
            'company_id' => $company->id,
            'contact_id' => $contact?->id,
            'user_id' => $user->id,
        ]);
    }
}
