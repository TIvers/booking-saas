<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Tenant context must be set with SET LOCAL app.tenant_id inside each transaction.
     */
    private const TABLES = [
        'tenants' => 'id',
        'memberships' => 'tenant_id',
        'locations' => 'tenant_id',
        'staff' => 'tenant_id',
        'services' => 'tenant_id',
        'bookings' => 'tenant_id',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $table => $tenantColumn) {
            DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
            DB::statement(
                "CREATE POLICY tenant_isolation_{$table} ON {$table}
                 USING ({$tenantColumn} = NULLIF(current_setting('app.tenant_id', true), '')::bigint)
                 WITH CHECK ({$tenantColumn} = NULLIF(current_setting('app.tenant_id', true), '')::bigint)"
            );
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table => $unusedTenantColumn) {
            DB::statement("DROP POLICY IF EXISTS tenant_isolation_{$table} ON {$table}");
            DB::statement("ALTER TABLE {$table} DISABLE ROW LEVEL SECURITY");
        }
    }
};
