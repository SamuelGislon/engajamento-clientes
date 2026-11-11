<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('trade_name')->nullable();
            $table->string('legal_name')->nullable();
            $table->string('tax_id', 32)->nullable();
            $table->string('segment')->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('email')->nullable();
            $table->string('website')->nullable();
            $table->string('city')->nullable();
            $table->char('state', 2)->nullable();
            $table->text('address')->nullable();
            $table->string('relationship_status', 24)->default('prospect');
            $table->string('lead_source')->nullable();
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'id']);
            $table->unique(['organization_id', 'tax_id']);
            $table->index(['organization_id', 'relationship_status', 'created_at']);
            $table->index(['organization_id', 'owner_user_id']);
        });

        Schema::table('companies', function (Blueprint $table) {
            $table->foreign(['organization_id', 'owner_user_id'])
                ->references(['organization_id', 'user_id'])
                ->on('organization_user')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('companies');
    }
};
