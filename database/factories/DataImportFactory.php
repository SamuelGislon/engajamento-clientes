<?php

namespace Database\Factories;

use App\Models\DataImport;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;

/**
 * @extends Factory<DataImport>
 */
class DataImportFactory extends Factory
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
            'created_by' => User::factory(),
            'record_type' => 'companies',
            'status' => 'uploaded',
            'original_filename' => 'companies.csv',
            'disk' => 'local',
            'file_path' => 'imports/test/companies.csv',
            'journey_mode' => 'none',
            'expires_at' => now()->addDay(),
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (DataImport $dataImport): void {
            DB::table('organization_user')->insertOrIgnore([
                'organization_id' => $dataImport->organization_id,
                'user_id' => $dataImport->created_by,
                'role' => 'owner',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }
}
