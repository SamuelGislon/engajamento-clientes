<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('commercial_alert_occurrences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id');
            $table->foreignId('task_id')->nullable();
            $table->foreignId('recommended_contact_id')->nullable();
            $table->string('type', 32);
            $table->string('occurrence_key', 180);
            $table->timestamp('anchor_at');
            $table->string('status', 16)->default('active');
            $table->text('reason');
            $table->timestamp('first_detected_at');
            $table->timestamp('resolved_at')->nullable();
            $table->string('resolution_reason', 180)->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'company_id', 'type', 'occurrence_key'], 'commercial_alert_occurrences_event_unique');
            $table->index(['organization_id', 'type', 'status', 'first_detected_at']);
            $table->index(['organization_id', 'task_id']);
            $table->foreign(['organization_id', 'company_id'])
                ->references(['organization_id', 'id'])->on('companies')->cascadeOnDelete();
            $table->foreign(['organization_id', 'task_id'])
                ->references(['organization_id', 'id'])->on('tasks')->restrictOnDelete();
            $table->foreign(['organization_id', 'company_id', 'recommended_contact_id'])
                ->references(['organization_id', 'company_id', 'contact_id'])->on('company_contacts')->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('commercial_alert_occurrences');
    }
};
