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
        Schema::create('staff', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('location_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('display_name');
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
            $table->index(['tenant_id', 'location_id', 'is_active']);
        });

        Schema::create('services', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->unsignedSmallInteger('duration_min');
            $table->unsignedSmallInteger('buffer_min')->default(0);
            $table->unsignedBigInteger('price_cents');
            $table->unsignedBigInteger('deposit_cents')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
            $table->index(['tenant_id', 'is_active']);
        });

        Schema::create('service_staff', function (Blueprint $table) {
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();
            $table->foreignId('staff_id')->constrained()->cascadeOnDelete();
            $table->timestampsTz();
            $table->primary(['service_id', 'staff_id']);
        });

        Schema::create('work_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('staff_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('weekday');
            $table->time('start_time');
            $table->time('end_time');
            $table->timestampsTz();
            $table->index(['staff_id', 'weekday']);
        });

        Schema::create('schedule_breaks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('staff_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('weekday');
            $table->time('start_time');
            $table->time('end_time');
            $table->timestampsTz();
            $table->index(['staff_id', 'weekday']);
        });

        Schema::create('schedule_exceptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('staff_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->boolean('is_day_off')->default(false);
            $table->time('start_time')->nullable();
            $table->time('end_time')->nullable();
            $table->timestampsTz();
            $table->unique(['staff_id', 'date']);
        });

        DB::statement(
            'ALTER TABLE services ADD CONSTRAINT services_duration_check CHECK (duration_min > 0)'
        );
        DB::statement(
            'ALTER TABLE services ADD CONSTRAINT services_deposit_check CHECK (deposit_cents <= price_cents)'
        );
        DB::statement(
            'ALTER TABLE work_schedules ADD CONSTRAINT work_schedules_weekday_check CHECK (weekday BETWEEN 1 AND 7)'
        );
        DB::statement(
            'ALTER TABLE work_schedules ADD CONSTRAINT work_schedules_time_check CHECK (end_time > start_time)'
        );
        DB::statement(
            'ALTER TABLE schedule_breaks ADD CONSTRAINT schedule_breaks_weekday_check CHECK (weekday BETWEEN 1 AND 7)'
        );
        DB::statement(
            'ALTER TABLE schedule_breaks ADD CONSTRAINT schedule_breaks_time_check CHECK (end_time > start_time)'
        );
        DB::statement(
            'ALTER TABLE schedule_exceptions ADD CONSTRAINT schedule_exceptions_time_check
             CHECK (
                 (is_day_off AND start_time IS NULL AND end_time IS NULL)
                 OR
                 (NOT is_day_off AND start_time IS NOT NULL AND end_time IS NOT NULL AND end_time > start_time)
             )'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('schedule_exceptions');
        Schema::dropIfExists('schedule_breaks');
        Schema::dropIfExists('work_schedules');
        Schema::dropIfExists('service_staff');
        Schema::dropIfExists('services');
        Schema::dropIfExists('staff');
    }
};
