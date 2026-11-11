<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journey_step_executions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('journey_enrollment_id');
            $table->foreignId('journey_step_id');
            $table->string('status', 16)->default('active');
            $table->timestamp('scheduled_at');
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'id']);
            $table->unique(['organization_id', 'journey_enrollment_id', 'journey_step_id']);
            $table->index(['organization_id', 'status', 'scheduled_at']);
            $table->foreign(['organization_id', 'journey_enrollment_id'])
                ->references(['organization_id', 'id'])->on('journey_enrollments')->restrictOnDelete();
            $table->foreign(['organization_id', 'journey_step_id'])
                ->references(['organization_id', 'id'])->on('journey_steps')->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('journey_step_executions');
    }
};
