<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            "CREATE POLICY google_identity_lookup ON user_identities
             FOR SELECT
             USING (
                provider = 'google'
                AND current_setting('app.google_identity_lookup', true) = 'on'
             )"
        );
        DB::statement(
            "CREATE POLICY google_email_lookup ON users
             FOR SELECT
             USING (current_setting('app.google_email_lookup', true) = 'on')"
        );
    }

    public function down(): void
    {
        DB::statement('DROP POLICY IF EXISTS google_email_lookup ON users');
        DB::statement('DROP POLICY IF EXISTS google_identity_lookup ON user_identities');
    }
};
