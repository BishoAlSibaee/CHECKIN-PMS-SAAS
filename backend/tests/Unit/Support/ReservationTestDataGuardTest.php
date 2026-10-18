<?php

namespace Tests\Unit\Support;

use App\Support\ReservationTestDataGuard;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class ReservationTestDataGuardTest extends TestCase
{
    private ?string $originalDatabase = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalDatabase = Config::get('database.connections.mysql.database');
    }

    protected function tearDown(): void
    {
        // Config/env mutations are process-wide state that outlives this
        // test regardless of any DB transaction — left unrestored, every
        // LATER test in the same PHPUnit process (this file runs first,
        // being a Unit test) silently queries the wrong database, surfacing
        // as baffling "unknown column" errors that look like a schema bug
        // but are really still-connected-to-the-wrong-database bugs.
        Config::set('database.connections.mysql.database', $this->originalDatabase);
        putenv('ALLOW_RESERVATION_SEED_PURGE');
        unset($_ENV['ALLOW_RESERVATION_SEED_PURGE'], $_SERVER['ALLOW_RESERVATION_SEED_PURGE']);

        parent::tearDown();
    }

    public function test_may_not_purge_on_protected_dev_database_even_in_testing(): void
    {
        Config::set('database.default', 'mysql');
        Config::set('database.connections.mysql.database', 'checkinbooking');

        putenv('ALLOW_RESERVATION_SEED_PURGE=true');
        $_ENV['ALLOW_RESERVATION_SEED_PURGE'] = 'true';
        $_SERVER['ALLOW_RESERVATION_SEED_PURGE'] = 'true';

        $this->assertTrue(ReservationTestDataGuard::isProtectedDevDatabase());
        $this->assertFalse(ReservationTestDataGuard::isIsolatedDatabase());
        $this->assertFalse(ReservationTestDataGuard::mayPurgeDemoYearReservations());
        $this->assertTrue(ReservationTestDataGuard::shouldBlockPHPUnit());
    }

    public function test_may_purge_on_isolated_test_database_when_flag_set(): void
    {
        Config::set('database.default', 'mysql');
        Config::set('database.connections.mysql.database', 'checkinbooking_test');

        putenv('ALLOW_RESERVATION_SEED_PURGE=true');
        $_ENV['ALLOW_RESERVATION_SEED_PURGE'] = 'true';
        $_SERVER['ALLOW_RESERVATION_SEED_PURGE'] = 'true';

        $this->assertTrue(ReservationTestDataGuard::isIsolatedDatabase());
        $this->assertTrue(ReservationTestDataGuard::mayPurgeDemoYearReservations());
        $this->assertFalse(ReservationTestDataGuard::shouldBlockPHPUnit());
    }
}
