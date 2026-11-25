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
        Schema::create('location_work_schedules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('location_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('weekday');
            $table->boolean('is_closed')->default(false);
            $table->time('start_time')->nullable();
            $table->time('end_time')->nullable();
            $table->timestampsTz();
            $table->unique(['location_id', 'weekday']);
            $table->index(['tenant_id', 'location_id']);
        });

        Schema::create('location_schedule_closures', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('location_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->string('reason', 255);
            $table->timestampsTz();
            $table->unique(['location_id', 'date']);
            $table->index(['tenant_id', 'location_id', 'date']);
        });

        DB::statement(
            'ALTER TABLE location_work_schedules ADD CONSTRAINT location_work_schedules_weekday_check
             CHECK (weekday BETWEEN 1 AND 7)'
        );
        DB::statement(
            'ALTER TABLE location_work_schedules ADD CONSTRAINT location_work_schedules_time_check
             CHECK (
                 (is_closed AND start_time IS NULL AND end_time IS NULL)
                 OR
                 (NOT is_closed AND start_time IS NOT NULL AND end_time IS NOT NULL AND end_time > start_time)
             )'
        );

        foreach (['location_work_schedules', 'location_schedule_closures'] as $table) {
            DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
            DB::statement(
                "CREATE POLICY tenant_isolation_{$table} ON {$table}
                 USING (tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::bigint)
                 WITH CHECK (tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::bigint)"
            );
            DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
        }
    }

    public function down(): void
    {
        foreach (['location_work_schedules', 'location_schedule_closures'] as $table) {
            DB::statement("ALTER TABLE {$table} NO FORCE ROW LEVEL SECURITY");
            DB::statement("DROP POLICY IF EXISTS tenant_isolation_{$table} ON {$table}");
            DB::statement("ALTER TABLE {$table} DISABLE ROW LEVEL SECURITY");
        }

        Schema::dropIfExists('location_schedule_closures');
        Schema::dropIfExists('location_work_schedules');
    }
};
