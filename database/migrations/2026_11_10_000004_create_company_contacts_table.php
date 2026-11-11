<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id');
            $table->foreignId('contact_id');
            $table->json('roles')->default('[]');
            $table->boolean('is_primary')->default(false);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'company_id', 'contact_id']);
            $table->index(['organization_id', 'contact_id']);
            $table->foreign(['organization_id', 'company_id'])
                ->references(['organization_id', 'id'])->on('companies')->cascadeOnDelete();
            $table->foreign(['organization_id', 'contact_id'])
                ->references(['organization_id', 'id'])->on('contacts')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_contacts');
    }
};
