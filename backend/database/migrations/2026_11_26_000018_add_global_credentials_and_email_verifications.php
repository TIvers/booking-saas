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
        Schema::table('users', function (Blueprint $table): void {
            $table->string('username', 32)->nullable();
            $table->timestampTz('password_set_at')->nullable();
            $table->boolean('email_verification_pending')->default(false);
        });

        DB::statement(
            'WITH user_names AS (
                SELECT user_id, MIN(LOWER(username)) AS username,
                       COUNT(DISTINCT LOWER(username)) AS name_count
                FROM memberships
                WHERE username IS NOT NULL
                GROUP BY user_id
            ), duplicate_names AS (
                SELECT LOWER(username) AS username
                FROM user_names
                WHERE name_count = 1
                GROUP BY LOWER(username)
                HAVING COUNT(*) > 1
            )
            UPDATE users
            SET username = user_names.username
            FROM user_names
            WHERE users.id = user_names.user_id
              AND user_names.name_count = 1
              AND NOT EXISTS (
                  SELECT 1 FROM duplicate_names
                  WHERE duplicate_names.username = LOWER(user_names.username)
              )'
        );
        DB::statement(
            'UPDATE users
             SET password_set_at = created_at
             WHERE NOT EXISTS (
                 SELECT 1 FROM user_identities
                 WHERE user_identities.user_id = users.id
             )
             OR EXISTS (
                 SELECT 1 FROM memberships
                 WHERE memberships.user_id = users.id
                   AND memberships.username IS NOT NULL
             )'
        );
        DB::statement(
            'CREATE UNIQUE INDEX users_username_lower_unique
             ON users (LOWER(username)) WHERE username IS NOT NULL'
        );
        Schema::create('email_verification_challenges', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('email');
            $table->string('purpose', 24);
            $table->string('channel', 8);
            $table->string('secret_hash', 64);
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestampTz('expires_at');
            $table->timestampTz('consumed_at')->nullable();
            $table->timestampsTz();
            $table->index(['tenant_id', 'user_id', 'purpose', 'consumed_at'], 'email_verification_user_idx');
        });

        DB::statement('ALTER TABLE email_verification_challenges ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE email_verification_challenges FORCE ROW LEVEL SECURITY');
        DB::statement(
            "CREATE POLICY tenant_isolation_email_verification_challenges
             ON email_verification_challenges
             USING (tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::bigint)
             WITH CHECK (tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::bigint)"
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE email_verification_challenges NO FORCE ROW LEVEL SECURITY');
        DB::statement('DROP POLICY IF EXISTS tenant_isolation_email_verification_challenges ON email_verification_challenges');
        DB::statement('ALTER TABLE email_verification_challenges DISABLE ROW LEVEL SECURITY');
        Schema::dropIfExists('email_verification_challenges');

        DB::statement('DROP INDEX IF EXISTS users_username_lower_unique');
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['username', 'password_set_at', 'email_verification_pending']);
        });
    }
};
