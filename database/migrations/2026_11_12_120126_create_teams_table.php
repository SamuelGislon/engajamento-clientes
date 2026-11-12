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
        Schema::create('teams', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->text('description')->nullable();
            $table->string('status', 16)->default('active');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['organization_id', 'id']);
            $table->unique(['organization_id', 'name']);
            $table->index(['organization_id', 'status', 'name']);
        });

        Schema::table('teams', function (Blueprint $table): void {
            $table->foreign(['organization_id', 'created_by'])
                ->references(['organization_id', 'user_id'])
                ->on('organization_user')
                ->restrictOnDelete();
        });

        Schema::create('team_memberships', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('team_id');
            $table->unsignedBigInteger('user_id');
            $table->string('role', 24);
            $table->boolean('is_active')->default(true);
            $table->timestamp('joined_at')->useCurrent();
            $table->timestamps();
            $table->unique(['team_id', 'user_id']);
            $table->unique(['organization_id', 'id']);
            $table->index(['organization_id', 'user_id', 'role', 'is_active']);
            $table->foreign(['organization_id', 'team_id'])
                ->references(['organization_id', 'id'])
                ->on('teams')
                ->cascadeOnDelete();
            $table->foreign(['organization_id', 'user_id'])
                ->references(['organization_id', 'user_id'])
                ->on('organization_user')
                ->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('team_memberships');
        Schema::dropIfExists('teams');
    }
};
