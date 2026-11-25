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
        Schema::table('tenants', function (Blueprint $table): void {
            $table->boolean('allow_customer_rescheduling')->default(true);
            $table->smallInteger('max_customer_reschedules')->nullable();
            $table->integer('booking_lead_time_minutes')->default(180);
        });

        Schema::table('services', function (Blueprint $table): void {
            $table->integer('booking_lead_time_minutes')->nullable();
        });

        DB::statement(
            'ALTER TABLE tenants ADD CONSTRAINT tenants_max_customer_reschedules_check
             CHECK (max_customer_reschedules IS NULL OR max_customer_reschedules IN (1, 2, 3))'
        );
        DB::statement(
            'ALTER TABLE tenants ADD CONSTRAINT tenants_booking_lead_time_check
             CHECK (booking_lead_time_minutes >= 0)'
        );
        DB::statement(
            'ALTER TABLE services ADD CONSTRAINT services_booking_lead_time_check
             CHECK (booking_lead_time_minutes IS NULL OR booking_lead_time_minutes >= 0)'
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE services DROP CONSTRAINT IF EXISTS services_booking_lead_time_check');
        DB::statement('ALTER TABLE tenants DROP CONSTRAINT IF EXISTS tenants_booking_lead_time_check');
        DB::statement('ALTER TABLE tenants DROP CONSTRAINT IF EXISTS tenants_max_customer_reschedules_check');

        Schema::table('services', function (Blueprint $table): void {
            $table->dropColumn('booking_lead_time_minutes');
        });
        Schema::table('tenants', function (Blueprint $table): void {
            $table->dropColumn([
                'allow_customer_rescheduling',
                'max_customer_reschedules',
                'booking_lead_time_minutes',
            ]);
        });
    }
};
