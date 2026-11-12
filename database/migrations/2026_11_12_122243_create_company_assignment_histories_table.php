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
        Schema::create('company_assignment_histories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('previous_owner_id')->nullable();
            $table->unsignedBigInteger('new_owner_id')->nullable();
            $table->unsignedBigInteger('previous_team_id')->nullable();
            $table->unsignedBigInteger('new_team_id')->nullable();
            $table->unsignedBigInteger('actor_id');
            $table->string('reason', 500)->nullable();
            $table->timestamp('changed_at');
            $table->timestamps();
            $table->unique(['organization_id', 'id']);
            $table->index(['organization_id', 'company_id', 'changed_at'], 'company_assignment_history_company_date_idx');
            $table->index(['organization_id', 'actor_id', 'changed_at'], 'company_assignment_history_actor_date_idx');
            $table->foreign(['organization_id', 'company_id'])
                ->references(['organization_id', 'id'])
                ->on('companies')
                ->cascadeOnDelete();
            $table->foreign(['organization_id', 'previous_owner_id'])
                ->references(['organization_id', 'user_id'])
                ->on('organization_user')
                ->restrictOnDelete();
            $table->foreign(['organization_id', 'new_owner_id'])
                ->references(['organization_id', 'user_id'])
                ->on('organization_user')
                ->restrictOnDelete();
            $table->foreign(['organization_id', 'previous_team_id'])
                ->references(['organization_id', 'id'])
                ->on('teams')
                ->restrictOnDelete();
            $table->foreign(['organization_id', 'new_team_id'])
                ->references(['organization_id', 'id'])
                ->on('teams')
                ->restrictOnDelete();
            $table->foreign(['organization_id', 'actor_id'])
                ->references(['organization_id', 'user_id'])
                ->on('organization_user')
                ->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('company_assignment_histories');
    }
};
