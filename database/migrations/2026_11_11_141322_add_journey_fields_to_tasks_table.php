<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table): void {
            $table->foreignId('journey_enrollment_id')->nullable()->after('origin');
            $table->foreignId('journey_step_execution_id')->nullable()->after('journey_enrollment_id');
            $table->unique(['organization_id', 'journey_step_execution_id'], 'tasks_journey_step_execution_unique');
            $table->index(['organization_id', 'journey_enrollment_id', 'status']);
            $table->foreign(['organization_id', 'journey_enrollment_id'])
                ->references(['organization_id', 'id'])->on('journey_enrollments')->restrictOnDelete();
            $table->foreign(['organization_id', 'journey_step_execution_id'])
                ->references(['organization_id', 'id'])->on('journey_step_executions')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table): void {
            $table->dropForeign(['organization_id', 'journey_enrollment_id']);
            $table->dropForeign(['organization_id', 'journey_step_execution_id']);
            $table->dropUnique('tasks_journey_step_execution_unique');
            $table->dropIndex(['organization_id', 'journey_enrollment_id', 'status']);
            $table->dropColumn(['journey_enrollment_id', 'journey_step_execution_id']);
        });
    }
};
