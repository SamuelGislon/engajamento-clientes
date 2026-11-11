<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id');
            $table->foreignId('contact_id')->nullable();
            $table->foreignId('user_id');
            $table->string('type', 32);
            $table->timestamp('occurred_at');
            $table->text('description');
            $table->text('result')->nullable();
            $table->text('next_action')->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'occurred_at']);
            $table->index(['organization_id', 'company_id', 'occurred_at']);
            $table->foreign(['organization_id', 'company_id'])
                ->references(['organization_id', 'id'])->on('companies')->cascadeOnDelete();
            $table->foreign(['organization_id', 'contact_id'])
                ->references(['organization_id', 'id'])->on('contacts')->restrictOnDelete();
            $table->foreign(['organization_id', 'user_id'])
                ->references(['organization_id', 'user_id'])->on('organization_user')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activities');
    }
};
