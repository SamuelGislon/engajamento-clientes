<?php

namespace Database\Factories;

use App\Models\Contact;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Contact> */
class ContactFactory extends Factory
{
    protected $model = Contact::class;

    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => fake()->phoneNumber(),
            'whatsapp' => null,
            'job_title' => fake()->jobTitle(),
            'preferred_channel' => null,
            'contact_preferences' => null,
            'professional_interests' => null,
            'notes' => null,
            'status' => 'active',
        ];
    }
}
