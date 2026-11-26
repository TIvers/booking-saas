<?php

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\DemoTenantSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Tests\Support\TenantFixtures;

uses(DatabaseMigrations::class);

it('preserves the demo owner profile name on repeated deployment seeding', function () {
    config()->set('booking.demo_owner.email', 'deployment-owner@example.test');
    config()->set('booking.demo_owner.password', 'deployment-owner-password');
    TenantFixtures::createTenant('studio-oliva');

    $seeder = app(DemoTenantSeeder::class);
    $seeder->run();

    $owner = User::query()->where('email', 'deployment-owner@example.test')->firstOrFail();
    $owner->name = 'Иванов Иван Иванович';
    $owner->save();

    $seeder->run();

    expect(User::query()->whereKey($owner->getKey())->value('name'))
        ->toBe('Иванов Иван Иванович');
});
