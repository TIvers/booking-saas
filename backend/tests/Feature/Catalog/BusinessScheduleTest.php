<?php

declare(strict_types=1);

use App\Models\LocationWorkSchedule;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Tests\Support\BookingFixtures;
use Tests\Support\TenantFixtures;

uses(DatabaseMigrations::class);

it('gives a newly created location the standard weekday hours', function () {
    $tenant = TenantFixtures::createTenant('business-hours-tenant');
    $owner = TenantFixtures::createMember($tenant);
    $headers = ['X-Tenant' => (string) $tenant->getKey()];

    $location = $this->actingAs($owner)
        ->withHeaders($headers)
        ->postJson('/api/v1/locations', [
            'name' => 'Main',
            'timezone' => 'UTC',
        ])
        ->assertCreated()
        ->json('data');

    $this->actingAs($owner)
        ->withHeaders($headers)
        ->getJson("/api/v1/manage/locations/{$location['id']}/business-schedule")
        ->assertOk()
        ->assertJsonCount(7, 'data.work_schedules')
        ->assertJsonPath('data.work_schedules.0.weekday', 1)
        ->assertJsonPath('data.work_schedules.0.is_closed', false)
        ->assertJsonPath('data.work_schedules.0.start_time', '10:00:00')
        ->assertJsonPath('data.work_schedules.0.end_time', '18:00:00')
        ->assertJsonPath('data.work_schedules.5.is_closed', true)
        ->assertJsonPath('data.work_schedules.6.is_closed', true);
});

it('limits employee slots to location hours and blocks location closure dates', function () {
    $fixtures = BookingFixtures::bookable();
    $headers = ['X-Tenant' => (string) $fixtures['tenant']->getKey()];
    $weekday = $fixtures['starts_at']->dayOfWeekIso;
    $schedule = array_map(static function (array $day) use ($weekday): array {
        if ($day['weekday'] === $weekday) {
            return [
                'weekday' => $weekday,
                'is_closed' => false,
                'start_time' => '12:00',
                'end_time' => '16:00',
                'lunch_start_time' => '13:00',
                'lunch_end_time' => '14:00',
            ];
        }

        return $day;
    }, LocationWorkSchedule::defaultWeek());
    $scheduleUrl = "/api/v1/manage/locations/{$fixtures['staff']->location_id}/business-schedule";
    $slotsUrl = '/api/v1/slots?'.http_build_query([
        'staff_id' => $fixtures['staff']->getKey(),
        'service_id' => $fixtures['service']->getKey(),
        'date' => $fixtures['starts_at']->format('Y-m-d'),
    ]);

    $this->actingAs($fixtures['owner'])
        ->withHeaders($headers)
        ->putJson($scheduleUrl, ['work_schedules' => $schedule])
        ->assertOk();

    $this->getJson($slotsUrl, $headers)
        ->assertOk()
        ->assertJsonPath('data.0.starts_at', $fixtures['starts_at']->addHours(2)->toIso8601String())
        ->assertJsonPath('data.3.starts_at', $fixtures['starts_at']->addHours(4)->toIso8601String());

    $this->actingAs($fixtures['owner'])
        ->withHeaders($headers)
        ->postJson(
            "/api/v1/manage/locations/{$fixtures['staff']->location_id}/closures",
            [
                'date' => $fixtures['starts_at']->format('Y-m-d'),
                'reason' => 'Праздничный день',
            ],
        )
        ->assertCreated()
        ->assertJsonPath('data.reason', 'Праздничный день');

    $this->getJson($slotsUrl, $headers)
        ->assertOk()
        ->assertExactJson(['data' => []]);
});

it('rejects invalid business hours and blank closure reasons', function () {
    $tenant = TenantFixtures::createTenant('invalid-business-hours-tenant');
    $owner = TenantFixtures::createMember($tenant);
    $location = TenantFixtures::createLocation($tenant, 'Main');
    $headers = ['X-Tenant' => (string) $tenant->getKey()];
    $scheduleUrl = "/api/v1/manage/locations/{$location->getKey()}/business-schedule";
    $schedule = LocationWorkSchedule::defaultWeek();
    $schedule[0]['start_time'] = '18:00';
    $schedule[0]['end_time'] = '10:00';

    $this->actingAs($owner)
        ->withHeaders($headers)
        ->putJson($scheduleUrl, ['work_schedules' => $schedule])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('work_schedules.0.end_time');

    $schedule = LocationWorkSchedule::defaultWeek();
    $schedule[0]['lunch_start_time'] = '09:00';
    $schedule[0]['lunch_end_time'] = '10:00';

    $this->actingAs($owner)
        ->withHeaders($headers)
        ->putJson($scheduleUrl, ['work_schedules' => $schedule])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('work_schedules.0.lunch_end_time');

    $this->actingAs($owner)
        ->withHeaders($headers)
        ->postJson("/api/v1/manage/locations/{$location->getKey()}/closures", [
            'date' => now()->addDay()->toDateString(),
            'reason' => ' ',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('reason');
});
