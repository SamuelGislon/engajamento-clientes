<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('data_imports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('created_by');
            $table->unsignedBigInteger('team_id')->nullable();
            $table->unsignedBigInteger('journey_template_id')->nullable();
            $table->string('record_type', 24);
            $table->string('status', 24)->default('uploaded');
            $table->string('original_filename', 255);
            $table->string('disk', 32)->default('local');
            $table->string('file_path');
            $table->string('journey_mode', 24)->default('none');
            $table->json('mapping')->nullable();
            $table->json('validation_errors')->nullable();
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('valid_rows')->default(0);
            $table->unsignedInteger('invalid_rows')->default(0);
            $table->unsignedInteger('created_rows')->default(0);
            $table->unsignedInteger('updated_rows')->default(0);
            $table->unsignedInteger('skipped_rows')->default(0);
            $table->unsignedInteger('duplicate_rows')->default(0);
            $table->timestamp('expires_at');
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'id']);
            $table->index(['organization_id', 'status', 'expires_at'], 'data_imports_org_status_expiry_idx');
            $table->foreign(['organization_id', 'created_by'])
                ->references(['organization_id', 'user_id'])
                ->on('organization_user')
                ->restrictOnDelete();
            $table->foreign(['organization_id', 'team_id'])
                ->references(['organization_id', 'id'])
                ->on('teams')
                ->restrictOnDelete();
            $table->foreign(['organization_id', 'journey_template_id'])
                ->references(['organization_id', 'id'])
                ->on('journey_templates')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('data_imports');
    }
};
