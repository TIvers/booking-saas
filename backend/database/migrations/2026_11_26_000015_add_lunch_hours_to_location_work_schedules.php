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
        Schema::table('location_work_schedules', function (Blueprint $table): void {
            $table->time('lunch_start_time')->nullable();
            $table->time('lunch_end_time')->nullable();
        });

        DB::statement(
            'ALTER TABLE location_work_schedules ADD CONSTRAINT location_work_schedules_lunch_check
             CHECK (
                 (lunch_start_time IS NULL AND lunch_end_time IS NULL)
                 OR
                 (
                     NOT is_closed
                     AND lunch_start_time IS NOT NULL
                     AND lunch_end_time IS NOT NULL
                     AND lunch_end_time > lunch_start_time
                     AND lunch_start_time >= start_time
                     AND lunch_end_time <= end_time
                 )
             )'
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE location_work_schedules DROP CONSTRAINT IF EXISTS location_work_schedules_lunch_check');

        Schema::table('location_work_schedules', function (Blueprint $table): void {
            $table->dropColumn(['lunch_start_time', 'lunch_end_time']);
        });
    }
};
