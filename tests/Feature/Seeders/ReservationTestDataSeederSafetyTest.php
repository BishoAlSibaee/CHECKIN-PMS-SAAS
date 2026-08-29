<?php

namespace Tests\Feature\Seeders;

use App\Models\Reservation;
use App\Support\ReservationTestDataGuard;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class ReservationTestDataSeederSafetyTest extends TestCase
{
    public function test_seeder_purges_only_on_isolated_test_database(): void
    {
        if (! ReservationTestDataGuard::isIsolatedDatabase()) {
            $this->markTestSkipped('PHPUnit is not using an isolated test database.');
        }

        $this->artisan('db:seed', [
            '--class' => 'Database\\Seeders\\ReservationTestDataSeeder',
            '--force' => true,
            '--no-interaction' => true,
        ]);

        $this->assertSame(12, Reservation::query()->whereYear('start_date', 2026)->count());
    }

    public function test_seeder_would_not_purge_protected_dev_database(): void
    {
        Config::set('database.default', 'mysql');
        Config::set('database.connections.mysql.database', 'checkinbooking');

        putenv('ALLOW_RESERVATION_SEED_PURGE=true');
        $_ENV['ALLOW_RESERVATION_SEED_PURGE'] = 'true';
        $_SERVER['ALLOW_RESERVATION_SEED_PURGE'] = 'true';

        $this->assertFalse(ReservationTestDataGuard::mayPurgeDemoYearReservations());
    }
}
