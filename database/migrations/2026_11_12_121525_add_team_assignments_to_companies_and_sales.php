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
        Schema::table('companies', function (Blueprint $table): void {
            $table->unsignedBigInteger('team_id')->nullable()->after('owner_user_id');
            $table->index(['organization_id', 'team_id'], 'companies_org_team_idx');
            $table->foreign(['organization_id', 'team_id'], 'companies_org_team_fk')
                ->references(['organization_id', 'id'])
                ->on('teams')
                ->restrictOnDelete();
        });

        Schema::table('sales', function (Blueprint $table): void {
            $table->unsignedBigInteger('team_id_at_sale')->nullable()->after('seller_id');
            $table->index(['organization_id', 'team_id_at_sale', 'status', 'sold_at'], 'sales_org_team_status_date_idx');
            $table->foreign(['organization_id', 'team_id_at_sale'], 'sales_org_team_fk')
                ->references(['organization_id', 'id'])
                ->on('teams')
                ->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table): void {
            $table->dropForeign('sales_org_team_fk');
            $table->dropIndex('sales_org_team_status_date_idx');
            $table->dropColumn('team_id_at_sale');
        });

        Schema::table('companies', function (Blueprint $table): void {
            $table->dropForeign('companies_org_team_fk');
            $table->dropIndex('companies_org_team_idx');
            $table->dropColumn('team_id');
        });
    }
};
