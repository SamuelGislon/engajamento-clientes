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
        Schema::create('commercial_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('sales_inactivity_days')->default(90);
            $table->unsignedSmallInteger('relationship_inactivity_days')->default(30);
            $table->unsignedSmallInteger('reactivation_repeat_cooldown_days')->default(60);
            $table->json('contact_frequency_days');
            $table->json('scoring_weights');
            $table->json('volume_score_bands');
            $table->json('frequency_score_bands');
            $table->json('tiers');
            $table->foreignId('post_sale_journey_template_id')->nullable();
            $table->foreignId('reactivation_journey_template_id')->nullable();
            $table->timestamps();

            $table->unique(['organization_id']);
            $table->foreign(['organization_id', 'post_sale_journey_template_id'])
                ->references(['organization_id', 'id'])->on('journey_templates')->restrictOnDelete();
            $table->foreign(['organization_id', 'reactivation_journey_template_id'])
                ->references(['organization_id', 'id'])->on('journey_templates')->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('commercial_settings');
    }
};
