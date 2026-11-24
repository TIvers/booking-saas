<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TENANT_TABLES = [
        'tenants',
        'users',
        'memberships',
        'locations',
        'staff',
        'services',
        'service_staff',
        'work_schedules',
        'schedule_breaks',
        'schedule_exceptions',
        'bookings',
        'payments',
    ];

    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->foreignId('tenant_id')->nullable()->constrained()->restrictOnDelete();
        });

        DB::statement(
            'UPDATE payments SET tenant_id = bookings.tenant_id FROM bookings WHERE bookings.id = payments.booking_id'
        );
        DB::statement('ALTER TABLE payments ALTER COLUMN tenant_id SET NOT NULL');

        DB::statement('ALTER TABLE users ENABLE ROW LEVEL SECURITY');
        DB::statement(
            "CREATE POLICY tenant_isolation_users ON users
             USING (
                EXISTS (SELECT 1 FROM memberships WHERE memberships.user_id = users.id)
                OR EXISTS (SELECT 1 FROM bookings WHERE bookings.customer_id = users.id)
                OR EXISTS (SELECT 1 FROM staff WHERE staff.user_id = users.id)
             )
             WITH CHECK (NULLIF(current_setting('app.tenant_id', true), '') IS NOT NULL)"
        );

        DB::statement('ALTER TABLE payments ENABLE ROW LEVEL SECURITY');
        DB::statement(
            "CREATE POLICY tenant_isolation_payments ON payments
             USING (tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::bigint)
             WITH CHECK (tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::bigint)"
        );

        DB::statement('ALTER TABLE service_staff ENABLE ROW LEVEL SECURITY');
        DB::statement(
            "CREATE POLICY tenant_isolation_service_staff ON service_staff
             USING (
                EXISTS (
                    SELECT 1 FROM services
                    WHERE services.id = service_staff.service_id
                      AND services.tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::bigint
                )
                AND EXISTS (
                    SELECT 1 FROM staff
                    WHERE staff.id = service_staff.staff_id
                      AND staff.tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::bigint
                )
             )
             WITH CHECK (
                EXISTS (
                    SELECT 1 FROM services
                    WHERE services.id = service_staff.service_id
                      AND services.tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::bigint
                )
                AND EXISTS (
                    SELECT 1 FROM staff
                    WHERE staff.id = service_staff.staff_id
                      AND staff.tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::bigint
                )
             )"
        );

        foreach (['work_schedules', 'schedule_breaks', 'schedule_exceptions'] as $table) {
            DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
            DB::statement(
                "CREATE POLICY tenant_isolation_{$table} ON {$table}
                 USING (
                    EXISTS (
                        SELECT 1 FROM staff
                        WHERE staff.id = {$table}.staff_id
                          AND staff.tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::bigint
                    )
                 )
                 WITH CHECK (
                    EXISTS (
                        SELECT 1 FROM staff
                        WHERE staff.id = {$table}.staff_id
                          AND staff.tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::bigint
                    )
                 )"
            );
        }

        foreach (self::TENANT_TABLES as $table) {
            DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
        }
    }

    public function down(): void
    {
        foreach (self::TENANT_TABLES as $table) {
            DB::statement("ALTER TABLE {$table} NO FORCE ROW LEVEL SECURITY");
        }

        foreach (['users', 'payments', 'service_staff', 'work_schedules', 'schedule_breaks', 'schedule_exceptions'] as $table) {
            DB::statement("DROP POLICY IF EXISTS tenant_isolation_{$table} ON {$table}");
            DB::statement("ALTER TABLE {$table} DISABLE ROW LEVEL SECURITY");
        }

        Schema::table('payments', function (Blueprint $table): void {
            $table->dropForeign(['tenant_id']);
            $table->dropColumn('tenant_id');
        });
    }
};
