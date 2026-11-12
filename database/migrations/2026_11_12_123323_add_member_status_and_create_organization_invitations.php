<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('organization_user', function (Blueprint $table): void {
            $table->boolean('is_active')->default(true)->after('role');
            $table->index(['organization_id', 'is_active', 'role'], 'organization_user_active_role_idx');
        });

        Schema::create('organization_invitations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('email', 255);
            $table->string('role', 24);
            $table->unsignedBigInteger('team_id')->nullable();
            $table->char('token_hash', 64)->unique();
            $table->string('status', 16)->default('pending');
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('created_by');
            $table->timestamps();
            $table->index(['organization_id', 'status', 'expires_at'], 'organization_invitations_org_status_expiry_idx');
            $table->index(['organization_id', 'email'], 'organization_invitations_org_email_idx');
            $table->foreign(['organization_id', 'team_id'])
                ->references(['organization_id', 'id'])
                ->on('teams')
                ->restrictOnDelete();
            $table->foreign(['organization_id', 'created_by'])
                ->references(['organization_id', 'user_id'])
                ->on('organization_user')
                ->restrictOnDelete();
        });

        DB::statement('CREATE UNIQUE INDEX organization_invitations_pending_email_unique ON organization_invitations (organization_id, email) WHERE accepted_at IS NULL AND revoked_at IS NULL');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS organization_invitations_pending_email_unique');
        Schema::dropIfExists('organization_invitations');

        Schema::table('organization_user', function (Blueprint $table): void {
            $table->dropIndex('organization_user_active_role_idx');
            $table->dropColumn('is_active');
        });
    }
};
