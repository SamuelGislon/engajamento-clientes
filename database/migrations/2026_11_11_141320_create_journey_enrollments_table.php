<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journey_enrollments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('journey_template_id');
            $table->foreignId('company_id');
            $table->foreignId('assigned_to');
            $table->string('status', 16)->default('active');
            $table->timestamp('enrolled_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('paused_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'id']);
            $table->index(['organization_id', 'status', 'enrolled_at']);
            $table->index(['organization_id', 'company_id', 'status']);
            $table->foreign(['organization_id', 'journey_template_id'])
                ->references(['organization_id', 'id'])->on('journey_templates')->restrictOnDelete();
            $table->foreign(['organization_id', 'company_id'])
                ->references(['organization_id', 'id'])->on('companies')->restrictOnDelete();
            $table->foreign(['organization_id', 'assigned_to'])
                ->references(['organization_id', 'user_id'])->on('organization_user')->restrictOnDelete();
        });
        DB::statement("CREATE UNIQUE INDEX journey_enrollments_active_company_unique ON journey_enrollments (organization_id, journey_template_id, company_id) WHERE status IN ('active', 'paused')");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS journey_enrollments_active_company_unique');
        Schema::dropIfExists('journey_enrollments');
    }
};
