<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('data_import_rows', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('data_import_id');
            $table->unsignedInteger('line_number');
            $table->string('group_key', 80);
            $table->string('status', 24)->default('pending');
            $table->longText('encrypted_payload')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'data_import_id', 'line_number'], 'data_import_rows_org_batch_line_uq');
            $table->index(['organization_id', 'data_import_id', 'status', 'line_number'], 'data_import_rows_org_batch_state_line_idx');
            $table->foreign(['organization_id', 'data_import_id'])
                ->references(['organization_id', 'id'])
                ->on('data_imports')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('data_import_rows');
    }
};
