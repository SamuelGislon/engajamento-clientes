<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tasks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->nullable();
            $table->foreignId('contact_id')->nullable();
            $table->foreignId('assigned_to');
            $table->foreignId('created_by');
            $table->string('title', 180);
            $table->text('description')->nullable();
            $table->string('type', 32);
            $table->string('priority', 16)->default('normal');
            $table->string('status', 16)->default('pending');
            $table->timestamp('due_at');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('completed_by')->nullable();
            $table->text('completion_notes')->nullable();
            $table->string('origin', 16)->default('manual');
            $table->timestamp('due_soon_notified_at')->nullable();
            $table->timestamp('overdue_notified_at')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'id']);
            $table->index(['organization_id', 'assigned_to', 'status', 'due_at']);
            $table->index(['organization_id', 'status', 'due_at']);
            $table->index(['organization_id', 'company_id', 'status', 'due_at']);
            $table->foreign(['organization_id', 'company_id'])
                ->references(['organization_id', 'id'])->on('companies')->restrictOnDelete();
            $table->foreign(['organization_id', 'contact_id'])
                ->references(['organization_id', 'id'])->on('contacts')->restrictOnDelete();
            $table->foreign(['organization_id', 'company_id', 'contact_id'])
                ->references(['organization_id', 'company_id', 'contact_id'])->on('company_contacts')->restrictOnDelete();
            $table->foreign(['organization_id', 'assigned_to'])
                ->references(['organization_id', 'user_id'])->on('organization_user')->restrictOnDelete();
            $table->foreign(['organization_id', 'created_by'])
                ->references(['organization_id', 'user_id'])->on('organization_user')->restrictOnDelete();
            $table->foreign(['organization_id', 'completed_by'])
                ->references(['organization_id', 'user_id'])->on('organization_user')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tasks');
    }
};
