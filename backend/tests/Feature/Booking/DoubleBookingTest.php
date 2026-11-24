<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use PgSql\Connection;

/*
|--------------------------------------------------------------------------
| Защита от двойного бронирования на уровне PostgreSQL
|--------------------------------------------------------------------------
| Здесь проверяется сам constraint bookings_no_overlap, без HTTP и приложения:
| корректность гарантирует БД. DatabaseMigrations (а не RefreshDatabase) нужен
| сознательно: тест конкурентности требует реально закоммиченных данных,
| видимых из других соединений, а не данных внутри тестовой транзакции.
|
| Тесты используют отдельную тестовую БД (DB_DATABASE в phpunit.xml).
|
| ВНИМАНИЕ: в helper-ах ниже указаны минимальные колонки справочных таблиц.
| Если ваша схема отличается, поправьте только seedFixtures() и seedStaff().
*/

uses(DatabaseMigrations::class);

const EXCLUSION_VIOLATION = '23P01';
const CHECK_VIOLATION = '23514';

/**
 * @return array{tenant: int, location: int, service: int, customer: int, staff: int}
 */
function seedFixtures(): array
{
    $now = now();

    $tenant = (int) DB::selectOne(
        "SELECT nextval(pg_get_serial_sequence('tenants', 'id')) AS id"
    )->id;
    DB::select("SELECT set_config('app.tenant_id', ?, false)", [(string) $tenant]);

    DB::table('tenants')->insert([
        'id' => $tenant,
        'name' => 'Demo Salon',
        'slug' => 'demo-salon',
        'timezone' => 'Europe/Berlin',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $location = DB::table('locations')->insertGetId([
        'tenant_id' => $tenant,
        'name' => 'Main',
        'address' => 'Test street 1',
        'timezone' => 'Europe/Berlin',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $service = DB::table('services')->insertGetId([
        'tenant_id' => $tenant,
        'name' => 'Haircut',
        'duration_min' => 60,
        'buffer_min' => 0,
        'price_cents' => 5000,
        'deposit_cents' => 1000,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $customer = DB::table('users')->insertGetId([
        'name' => 'Customer',
        'email' => 'customer@example.test',
        'password' => 'not-a-real-hash',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $fixtures = [
        'tenant' => $tenant,
        'location' => $location,
        'service' => $service,
        'customer' => $customer,
    ];

    return $fixtures + ['staff' => seedStaff($fixtures, 'Anna')];
}

/**
 * @param  array{tenant: int, location: int}  $fixtures
 */
function seedStaff(array $fixtures, string $name): int
{
    return DB::table('staff')->insertGetId([
        'tenant_id' => $fixtures['tenant'],
        'location_id' => $fixtures['location'],
        'display_name' => $name,
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/**
 * @param  array{tenant: int, service: int, customer: int, staff: int}  $fixtures
 */
function insertBooking(
    array $fixtures,
    string $startsAt,
    string $endsAt,
    string $status = 'confirmed',
    ?int $staffId = null,
): int {
    return DB::table('bookings')->insertGetId([
        'tenant_id' => $fixtures['tenant'],
        'staff_id' => $staffId ?? $fixtures['staff'],
        'service_id' => $fixtures['service'],
        'customer_id' => $fixtures['customer'],
        'starts_at' => $startsAt,
        'ends_at' => $endsAt,
        'status' => $status,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/** Выполняет действие и возвращает SQLSTATE ошибки БД (или null, если ошибки не было). */
function sqlStateOf(callable $action): ?string
{
    try {
        $action();
    } catch (QueryException $e) {
        return (string) $e->getCode();
    }

    return null;
}

it('rejects an overlapping booking for the same staff member', function () {
    $f = seedFixtures();
    insertBooking($f, '2026-06-01 10:00:00+00', '2026-06-01 11:00:00+00');

    $state = sqlStateOf(
        fn () => insertBooking($f, '2026-06-01 10:30:00+00', '2026-06-01 11:30:00+00', 'pending'),
    );

    expect($state)->toBe(EXCLUSION_VIOLATION);
    expect(DB::table('bookings')->count())->toBe(1);
});

it('rejects a booking fully inside another one', function () {
    $f = seedFixtures();
    insertBooking($f, '2026-06-01 10:00:00+00', '2026-06-01 12:00:00+00');

    $state = sqlStateOf(
        fn () => insertBooking($f, '2026-06-01 10:30:00+00', '2026-06-01 11:00:00+00'),
    );

    expect($state)->toBe(EXCLUSION_VIOLATION);
});

it('allows back-to-back bookings because intervals are half-open', function () {
    $f = seedFixtures();
    insertBooking($f, '2026-06-01 10:00:00+00', '2026-06-01 11:00:00+00');

    insertBooking($f, '2026-06-01 11:00:00+00', '2026-06-01 12:00:00+00');
    insertBooking($f, '2026-06-01 09:00:00+00', '2026-06-01 10:00:00+00');

    expect(DB::table('bookings')->count())->toBe(3);
});

it('allows the same time slot for different staff members', function () {
    $f = seedFixtures();
    $other = seedStaff($f, 'Boris');

    insertBooking($f, '2026-06-01 10:00:00+00', '2026-06-01 11:00:00+00');
    insertBooking($f, '2026-06-01 10:00:00+00', '2026-06-01 11:00:00+00', staffId: $other);

    expect(DB::table('bookings')->count())->toBe(2);
});

it('frees the slot when a booking is cancelled', function () {
    $f = seedFixtures();
    $id = insertBooking($f, '2026-06-01 10:00:00+00', '2026-06-01 11:00:00+00');

    DB::table('bookings')->where('id', $id)->update(['status' => 'cancelled', 'cancelled_at' => now()]);

    // Слот снова свободен, дополнительных действий не требуется.
    insertBooking($f, '2026-06-01 10:00:00+00', '2026-06-01 11:00:00+00');

    expect(DB::table('bookings')->where('status', 'confirmed')->count())->toBe(1);
});

it('blocks a slot with a pending reservation as well', function () {
    $f = seedFixtures();
    insertBooking($f, '2026-06-01 10:00:00+00', '2026-06-01 11:00:00+00', 'pending');

    $state = sqlStateOf(
        fn () => insertBooking($f, '2026-06-01 10:00:00+00', '2026-06-01 11:00:00+00'),
    );

    expect($state)->toBe(EXCLUSION_VIOLATION);
});

it('does not allow reviving a cancelled booking into an occupied slot', function () {
    $f = seedFixtures();
    $cancelled = insertBooking($f, '2026-06-01 10:00:00+00', '2026-06-01 11:00:00+00', 'cancelled');
    insertBooking($f, '2026-06-01 10:00:00+00', '2026-06-01 11:00:00+00');

    $state = sqlStateOf(
        fn () => DB::table('bookings')->where('id', $cancelled)->update(['status' => 'confirmed']),
    );

    expect($state)->toBe(EXCLUSION_VIOLATION);
});

it('rejects a booking that ends before it starts', function () {
    $f = seedFixtures();

    $state = sqlStateOf(
        fn () => insertBooking($f, '2026-06-01 11:00:00+00', '2026-06-01 10:00:00+00'),
    );

    expect($state)->toBe(CHECK_VIOLATION);
});

/*
|--------------------------------------------------------------------------
| Настоящая конкурентность
|--------------------------------------------------------------------------
| Два независимых соединения к Postgres. Первое вставляет запись и держит транзакцию
| открытой, второе пытается занять тот же слот. Postgres заставляет второе соединение
| ждать завершения первой транзакции и только потом выносит решение.
| Требуется расширение PHP pgsql (функции pg_*), иначе тесты пропускаются.
*/

/** @return array{0: Connection, 1: Connection} */
function openTwoConnections(int $tenantId): array
{
    $config = config('database.connections.'.config('database.default'));

    $quote = fn (mixed $value): string => "'".addcslashes((string) $value, "'\\")."'";

    $dsn = sprintf(
        'host=%s port=%s dbname=%s user=%s password=%s',
        $quote($config['host']),
        $quote($config['port'] ?? 5432),
        $quote($config['database']),
        $quote($config['username']),
        $quote($config['password']),
    );

    $connections = [
        pg_connect($dsn, PGSQL_CONNECT_FORCE_NEW),
        pg_connect($dsn, PGSQL_CONNECT_FORCE_NEW),
    ];

    foreach ($connections as $connection) {
        pg_query_params(
            $connection,
            "SELECT set_config('app.tenant_id', $1, false)",
            [(string) $tenantId],
        );
    }

    return $connections;
}

/** @param array{tenant: int, staff: int, service: int, customer: int} $f */
function rawInsertSql(array $f): string
{
    return sprintf(
        "INSERT INTO bookings (tenant_id, staff_id, service_id, customer_id, starts_at, ends_at, status, created_at, updated_at)
         VALUES (%d, %d, %d, %d, '2026-06-01 10:00:00+00', '2026-06-01 11:00:00+00', 'pending', now(), now())",
        $f['tenant'],
        $f['staff'],
        $f['service'],
        $f['customer'],
    );
}

it('lets exactly one of two concurrent transactions take the same slot', function () {
    $f = seedFixtures();
    [$a, $b] = openTwoConnections($f['tenant']);
    $sql = rawInsertSql($f);

    pg_query($a, 'BEGIN');
    pg_query($a, $sql);          // A занял слот, но ещё не закоммитил
    pg_send_query($b, $sql);     // B пытается занять тот же слот и должен упереться в A

    usleep(300_000);
    expect(pg_connection_busy($b))->toBeTrue(); // B действительно ждёт, а не отработал мгновенно

    pg_query($a, 'COMMIT');      // A победил
    $result = pg_get_result($b);

    expect(pg_result_error_field($result, PGSQL_DIAG_SQLSTATE))->toBe(EXCLUSION_VIOLATION);
    expect(DB::table('bookings')->count())->toBe(1);

    pg_close($a);
    pg_close($b);
})->skip(! function_exists('pg_connect'), 'Нужно расширение PHP pgsql');

it('lets the waiting transaction take the slot if the first one rolls back', function () {
    $f = seedFixtures();
    [$a, $b] = openTwoConnections($f['tenant']);
    $sql = rawInsertSql($f);

    pg_query($a, 'BEGIN');
    pg_query($a, $sql);
    pg_send_query($b, $sql);

    usleep(300_000);
    expect(pg_connection_busy($b))->toBeTrue();

    pg_query($a, 'ROLLBACK');    // A отказался от слота, B должен его получить
    $result = pg_get_result($b);

    expect(pg_result_error_field($result, PGSQL_DIAG_SQLSTATE))->toBeNull();
    expect(DB::table('bookings')->count())->toBe(1);

    pg_close($a);
    pg_close($b);
})->skip(! function_exists('pg_connect'), 'Нужно расширение PHP pgsql');
