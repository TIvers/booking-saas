<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const TABLES = [
        'users',
        'memberships',
        'staff',
        'bookings',
        'booking_changes',
        'user_identities',
        'telegram_subscriptions',
        'telegram_subscription_tokens',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            DB::statement(
                "CREATE POLICY account_merge_access_{$table} ON {$table}
                 FOR ALL
                 USING (current_setting('app.account_merge', true) = 'on')
                 WITH CHECK (current_setting('app.account_merge', true) = 'on')"
            );
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            DB::statement("DROP POLICY IF EXISTS account_merge_access_{$table} ON {$table}");
        }
    }
};
