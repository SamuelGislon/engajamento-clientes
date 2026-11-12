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
        Schema::create('customer_scores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id');
            $table->unsignedTinyInteger('volume_points')->default(0);
            $table->unsignedTinyInteger('frequency_points')->default(0);
            $table->unsignedTinyInteger('potential_points')->nullable();
            $table->unsignedTinyInteger('relationship_points')->nullable();
            $table->unsignedTinyInteger('total_score')->nullable();
            $table->char('tier', 1)->nullable();
            $table->boolean('is_complete')->default(false);
            $table->timestamp('last_calculated_at');
            $table->timestamps();

            $table->unique(['organization_id', 'company_id']);
            $table->index(['organization_id', 'tier', 'total_score']);
            $table->foreign(['organization_id', 'company_id'])
                ->references(['organization_id', 'id'])->on('companies')->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customer_scores');
    }
};
