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
            $table->json('duplicate_resolutions')->nullable()->after('validation_errors');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('data_imports', function (Blueprint $table): void {
            $table->dropColumn('duplicate_resolutions');
        });
    }
};
