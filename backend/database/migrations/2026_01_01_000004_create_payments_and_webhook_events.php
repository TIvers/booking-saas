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
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 30);
            $table->string('provider_payment_id')->nullable()->unique();
            $table->string('status', 20)->default('pending');
            $table->unsignedBigInteger('amount_cents');
            $table->char('currency', 3)->default('USD');
            $table->timestampsTz();
            $table->index(['booking_id', 'status']);
        });

        Schema::create('webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 30);
            $table->string('event_id', 255);
            $table->jsonb('payload');
            $table->timestampTz('processed_at')->nullable();
            $table->timestampsTz();
            $table->unique(['provider', 'event_id']);
        });

        DB::statement(
            "ALTER TABLE payments ADD CONSTRAINT payments_status_check
             CHECK (status IN ('pending', 'succeeded', 'failed', 'refunded'))"
        );
        DB::statement(
            'ALTER TABLE payments ADD CONSTRAINT payments_amount_check CHECK (amount_cents >= 0)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_events');
        Schema::dropIfExists('payments');
    }
};
