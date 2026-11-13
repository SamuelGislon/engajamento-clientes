<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('data_imports', function (Blueprint $table): void {
            $table->unsignedInteger('processed_rows')->default(0)->after('valid_rows');
            $table->unsignedInteger('processing_attempts')->default(0)->after('duplicate_rows');
            $table->unsignedInteger('last_processed_line')->nullable()->after('processing_attempts');
            $table->text('last_error')->nullable()->after('last_processed_line');
            $table->timestamp('processing_started_at')->nullable()->after('last_error');
            $table->timestamp('failed_at')->nullable()->after('processing_started_at');
        });
    }

    public function down(): void
    {
        Schema::table('data_imports', function (Blueprint $table): void {
            $table->dropColumn([
                'processed_rows',
                'processing_attempts',
                'last_processed_line',
                'last_error',
                'processing_started_at',
                'failed_at',
            ]);
        });
    }
};
