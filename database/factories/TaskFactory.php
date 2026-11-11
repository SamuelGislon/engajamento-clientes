<?php

namespace Database\Factories;

use App\Models\Organization;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Task>
 */
class TaskFactory extends Factory
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
            'company_id' => null,
            'contact_id' => null,
            'assigned_to' => User::factory(),
            'created_by' => User::factory(),
            'title' => fake()->sentence(4),
            'description' => fake()->optional()->sentence(),
            'type' => 'custom',
            'priority' => 'normal',
            'status' => 'pending',
            'due_at' => now()->addDay(),
            'started_at' => null,
            'completed_at' => null,
            'completed_by' => null,
            'completion_notes' => null,
            'origin' => 'manual',
        ];
    }
}
