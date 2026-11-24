<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Таблица записей и гарантия отсутствия двойного бронирования на уровне БД.
 *
 * Ключевые решения:
 *  - все моменты времени в timestamptz (хранение в UTC);
 *  - ends_at включает буфер после услуги, поэтому constraint защищает и буфер;
 *  - интервал полуоткрытый [starts_at, ends_at): запись, начинающаяся ровно в момент
 *    окончания предыдущей, конфликтом не считается;
 *  - EXCLUDE действует только для статусов pending и confirmed: отменённая, завершённая
 *    или неявившаяся запись слот не занимает, и отдельно освобождать ничего не нужно.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Нужно для оператора "=" по bigint внутри GiST-индекса.
        DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist');

        Schema::create('bookings', function (Blueprint $table) {
            $table->id();

            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('staff_id')->constrained('staff')->restrictOnDelete();
            $table->foreignId('service_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->constrained('users')->restrictOnDelete();

            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at'); // с учётом буфера после услуги
            $table->string('status', 20)->default('pending');

            // Конец резерва для pending-записи (ожидание оплаты). Для confirmed это null.
            $table->timestampTz('expires_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();

            $table->timestampsTz();

            // Календарь сотрудника и генерация слотов: записи сотрудника за период.
            $table->index(['tenant_id', 'staff_id', 'starts_at'], 'bookings_tenant_staff_starts_idx');
            // «Мои записи» у клиента.
            $table->index(['customer_id', 'starts_at'], 'bookings_customer_starts_idx');
        });

        DB::statement(
            'ALTER TABLE bookings ADD CONSTRAINT bookings_time_order_check CHECK (ends_at > starts_at)'
        );

        DB::statement(
            "ALTER TABLE bookings ADD CONSTRAINT bookings_status_check
             CHECK (status IN ('pending', 'confirmed', 'completed', 'cancelled', 'no_show'))"
        );

        // Job ReleaseExpiredReservations ищет только pending-записи, поэтому индекс частичный.
        DB::statement(
            "CREATE INDEX bookings_pending_expires_idx ON bookings (expires_at) WHERE status = 'pending'"
        );

        // Главная защита: у одного сотрудника не может быть двух активных записей,
        // пересекающихся по времени. Нарушение даёт SQLSTATE 23P01 (exclusion_violation),
        // который CreateBookingAction превращает в SlotAlreadyTakenException (HTTP 409).
        DB::statement(
            "ALTER TABLE bookings ADD CONSTRAINT bookings_no_overlap
             EXCLUDE USING gist (
                 staff_id WITH =,
                 tstzrange(starts_at, ends_at, '[)') WITH &&
             )
             WHERE (status IN ('pending', 'confirmed'))"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('bookings');
        // Расширение btree_gist намеренно не удаляем: оно может использоваться другими таблицами.
    }
};
