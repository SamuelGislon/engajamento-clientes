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
            $table->unsignedTinyInteger('queue_dispatches')->default(0)->after('processing_attempts');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('data_imports', function (Blueprint $table): void {
            $table->dropColumn('queue_dispatches');
        });
    }
};
