<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            'CREATE UNIQUE INDEX staff_tenant_user_unique ON staff (tenant_id, user_id)
             WHERE user_id IS NOT NULL'
        );

        Schema::table('memberships', function (Blueprint $table): void {
            $table->string('username', 32)->nullable();
            $table->unique(['tenant_id', 'username'], 'memberships_tenant_username_unique');
        });

        DB::statement('ALTER TABLE users ALTER COLUMN email DROP NOT NULL');

        Schema::create('booking_changes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 20);
            $table->timestampTz('from_starts_at');
            $table->timestampTz('to_starts_at')->nullable();
            $table->text('reason')->nullable();
            $table->timestampsTz();
            $table->index(['tenant_id', 'booking_id', 'created_at'], 'booking_changes_tenant_booking_created_idx');
        });

        DB::statement('ALTER TABLE booking_changes ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE booking_changes FORCE ROW LEVEL SECURITY');
        DB::statement(
            "CREATE POLICY tenant_isolation_booking_changes ON booking_changes
             USING (tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::bigint)
             WITH CHECK (tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::bigint)"
        );

        Schema::create('user_identities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 32);
            $table->string('provider_subject', 191);
            $table->string('email')->nullable();
            $table->timestampTz('email_verified_at')->nullable();
            $table->timestampsTz();
            $table->unique(['provider', 'provider_subject'], 'user_identities_provider_subject_unique');
            $table->index('user_id');
        });

        DB::statement('ALTER TABLE user_identities ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE user_identities FORCE ROW LEVEL SECURITY');
        DB::statement(
            "CREATE POLICY tenant_isolation_user_identities ON user_identities
             USING (
                EXISTS (
                    SELECT 1 FROM memberships
                    WHERE memberships.user_id = user_identities.user_id
                      AND memberships.tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::bigint
                )
             )
             WITH CHECK (
                EXISTS (
                    SELECT 1 FROM memberships
                    WHERE memberships.user_id = user_identities.user_id
                      AND memberships.tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::bigint
                )
             )"
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE user_identities NO FORCE ROW LEVEL SECURITY');
        DB::statement('DROP POLICY IF EXISTS tenant_isolation_user_identities ON user_identities');
        DB::statement('ALTER TABLE user_identities DISABLE ROW LEVEL SECURITY');
        Schema::dropIfExists('user_identities');

        DB::statement('ALTER TABLE booking_changes NO FORCE ROW LEVEL SECURITY');
        DB::statement('DROP POLICY IF EXISTS tenant_isolation_booking_changes ON booking_changes');
        DB::statement('ALTER TABLE booking_changes DISABLE ROW LEVEL SECURITY');
        Schema::dropIfExists('booking_changes');

        // Keep email nullable on rollback so accounts created without email remain valid.
        Schema::table('memberships', function (Blueprint $table): void {
            $table->dropUnique('memberships_tenant_username_unique');
            $table->dropColumn('username');
        });
        DB::statement('DROP INDEX IF EXISTS staff_tenant_user_unique');
    }
};
