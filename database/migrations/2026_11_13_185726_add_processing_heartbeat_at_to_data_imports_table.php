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
        Schema::table('data_imports', function (Blueprint $table): void {
            $table->timestamp('processing_heartbeat_at')->nullable()->after('processing_started_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('data_imports', function (Blueprint $table): void {
            $table->dropColumn('processing_heartbeat_at');
        });
    }
};
