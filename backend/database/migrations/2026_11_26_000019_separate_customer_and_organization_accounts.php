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
            $table->string('account_type', 16)->default('customer');
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropUnique('users_email_unique');
        });
        DB::statement('DROP INDEX IF EXISTS users_username_lower_unique');
        DB::statement('DROP INDEX IF EXISTS users_email_lower_unique');
        Schema::table('memberships', function (Blueprint $table): void {
            $table->dropUnique('memberships_tenant_username_unique');
        });

        DB::statement(
            "ALTER TABLE users
             ADD CONSTRAINT users_account_type_check
             CHECK (account_type IN ('customer', 'organization'))"
        );
        DB::statement(
            "UPDATE users
             SET account_type = 'organization'
             WHERE EXISTS (
                 SELECT 1
                 FROM memberships
                 WHERE memberships.user_id = users.id
                   AND memberships.role IN ('owner', 'manager', 'staff')
             )"
        );

        DB::statement(
            "CREATE TEMP TABLE account_type_user_splits AS
             SELECT users.id AS organization_user_id,
                    nextval(pg_get_serial_sequence('users', 'id')) AS customer_user_id
             FROM users
             WHERE users.account_type = 'organization'
               AND (
                   EXISTS (
                       SELECT 1 FROM memberships
                       WHERE memberships.user_id = users.id
                         AND memberships.role = 'customer'
                   )
                   OR EXISTS (
                       SELECT 1 FROM user_identities
                       WHERE user_identities.user_id = users.id
                   )
                   OR EXISTS (
                       SELECT 1 FROM bookings
                       WHERE bookings.customer_id = users.id
                   )
                   OR EXISTS (
                       SELECT 1 FROM telegram_subscriptions
                       WHERE telegram_subscriptions.user_id = users.id
                   )
                   OR EXISTS (
                       SELECT 1 FROM telegram_subscription_tokens
                       WHERE telegram_subscription_tokens.user_id = users.id
                   )
               )"
        );
        DB::statement(
            "INSERT INTO users (
                 id, name, email, email_verified_at, username, phone, password,
                 password_set_at, email_verification_pending, remember_token,
                 account_type, created_at, updated_at
             )
             SELECT splits.customer_user_id, users.name, users.email,
                    users.email_verified_at, users.username, users.phone,
                    users.password, users.password_set_at,
                    users.email_verification_pending, users.remember_token,
                    'customer', users.created_at, users.updated_at
             FROM account_type_user_splits AS splits
             JOIN users ON users.id = splits.organization_user_id"
        );
        DB::statement(
            "UPDATE memberships
             SET user_id = splits.customer_user_id
             FROM account_type_user_splits AS splits
             WHERE memberships.user_id = splits.organization_user_id
               AND memberships.role = 'customer'"
        );

        foreach ([
            'user_identities' => 'user_id',
            'bookings' => 'customer_id',
            'telegram_subscriptions' => 'user_id',
            'telegram_subscription_tokens' => 'user_id',
        ] as $table => $column) {
            DB::statement(
                "UPDATE {$table}
                 SET {$column} = splits.customer_user_id
                 FROM account_type_user_splits AS splits
                 WHERE {$table}.{$column} = splits.organization_user_id"
            );
        }
        DB::statement('DROP TABLE account_type_user_splits');

        DB::statement(
            'CREATE UNIQUE INDEX users_account_type_email_lower_unique
             ON users (account_type, LOWER(email)) WHERE email IS NOT NULL'
        );
        DB::statement(
            'CREATE UNIQUE INDEX users_account_type_username_lower_unique
             ON users (account_type, LOWER(username)) WHERE username IS NOT NULL'
        );
    }

    public function down(): void
    {
        DB::statement("SELECT set_config('app.account_merge', 'on', true)");

        $hasDuplicateEmails = DB::table('users')
            ->whereNotNull('email')
            ->select('email')
            ->groupBy('email')
            ->havingRaw('COUNT(*) > 1')
            ->exists();
        $hasDuplicateUsernames = DB::table('users')
            ->whereNotNull('username')
            ->selectRaw('LOWER(username)')
            ->groupByRaw('LOWER(username)')
            ->havingRaw('COUNT(*) > 1')
            ->exists();
        $hasDuplicateTenantUsernames = DB::table('memberships')
            ->whereNotNull('username')
            ->select(['tenant_id', 'username'])
            ->groupBy(['tenant_id', 'username'])
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if ($hasDuplicateEmails || $hasDuplicateUsernames || $hasDuplicateTenantUsernames) {
            throw new RuntimeException(
                'Cannot reverse account separation while customer and organization credentials conflict; restore a database backup.'
            );
        }

        DB::statement('DROP INDEX IF EXISTS users_account_type_email_lower_unique');
        DB::statement('DROP INDEX IF EXISTS users_account_type_username_lower_unique');
        DB::statement('ALTER TABLE users DROP CONSTRAINT users_account_type_check');
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('account_type');
            $table->unique('email', 'users_email_unique');
        });
        DB::statement(
            'CREATE UNIQUE INDEX users_username_lower_unique
             ON users (LOWER(username)) WHERE username IS NOT NULL'
        );
        Schema::table('memberships', function (Blueprint $table): void {
            $table->unique(['tenant_id', 'username'], 'memberships_tenant_username_unique');
        });
    }
};
