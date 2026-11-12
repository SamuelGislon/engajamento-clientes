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
        Schema::create('sales', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id');
            $table->foreignId('seller_id');
            $table->foreignId('created_by');
            $table->string('external_id', 100)->nullable();
            $table->string('description', 255);
            $table->decimal('amount', 14, 2);
            $table->char('currency', 3)->default('BRL');
            $table->timestamp('sold_at');
            $table->string('status', 16)->default('confirmed');
            $table->text('notes')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'id']);
            $table->unique(['organization_id', 'external_id']);
            $table->index(['organization_id', 'status', 'sold_at']);
            $table->index(['organization_id', 'company_id', 'status', 'sold_at']);
            $table->foreign(['organization_id', 'company_id'])
                ->references(['organization_id', 'id'])->on('companies')->restrictOnDelete();
            $table->foreign(['organization_id', 'seller_id'])
                ->references(['organization_id', 'user_id'])->on('organization_user')->restrictOnDelete();
            $table->foreign(['organization_id', 'created_by'])
                ->references(['organization_id', 'user_id'])->on('organization_user')->restrictOnDelete();
            $table->foreign(['organization_id', 'cancelled_by'])
                ->references(['organization_id', 'user_id'])->on('organization_user')->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sales');
    }
};
