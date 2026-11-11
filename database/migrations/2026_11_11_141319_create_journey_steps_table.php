<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journey_steps', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('journey_template_id');
            $table->string('title', 180);
            $table->text('description')->nullable();
            $table->unsignedSmallInteger('position');
            $table->unsignedSmallInteger('delay_days')->default(0);
            $table->string('task_title', 180);
            $table->text('task_description')->nullable();
            $table->string('task_type', 32)->default('custom');
            $table->string('task_priority', 16)->default('normal');
            $table->timestamps();

            $table->unique(['organization_id', 'id']);
            $table->unique(['organization_id', 'journey_template_id', 'position']);
            $table->index(['organization_id', 'journey_template_id', 'position']);
            $table->foreign(['organization_id', 'journey_template_id'])
                ->references(['organization_id', 'id'])->on('journey_templates')->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('journey_steps');
    }
};
