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
        Schema::table('customer_score_histories', function (Blueprint $table) {
            $table->string('tier_name', 60)->nullable();
            $table->char('tier_color', 7)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('customer_score_histories', function (Blueprint $table) {
            $table->dropColumn(['tier_name', 'tier_color']);
        });
    }
};
