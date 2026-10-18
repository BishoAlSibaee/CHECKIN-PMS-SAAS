<?php

namespace Tests\Feature\Seeders;

use App\Models\Guest_classification;
use App\Models\Reservation;
use App\Models\Reservation_source;
use App\Models\Room;
use App\Models\Stay_reason;
use App\Models\User;
use Database\Seeders\AdminSeeder as AdminSeederClass;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class EssentialDatabaseSeederTest extends TestCase
{
    // Deliberately NOT DatabaseTransactions — this test calls migrate:fresh
    // itself, and DDL causes an implicit COMMIT in MySQL/InnoDB, which would
    // end PHPUnit's wrapping transaction early and desync the connection's
    // transaction-nesting counter for every test that runs after this one in
    // the same process (cascading, hard-to-trace failures elsewhere).

    public function test_default_seed_installs_admin_rbac_and_reference_data_only(): void
    {
        $this->artisan('migrate:fresh', ['--force' => true, '--no-interaction' => true]);
        $this->artisan('db:seed', ['--force' => true, '--no-interaction' => true]);

        $this->assertGreaterThan(0, Permission::count());
        $this->assertTrue(Role::where('name', 'admin')->where('guard_name', 'api')->exists());

        $admin = User::where('job_number', AdminSeederClass::JOB_NUMBER)->first();
        $this->assertNotNull($admin);
        $this->assertTrue($admin->hasRole('admin'));

        $this->assertGreaterThanOrEqual(4, Stay_reason::count());
        $this->assertGreaterThanOrEqual(5, Reservation_source::count());
        $this->assertGreaterThanOrEqual(2, Guest_classification::count());

        $this->assertSame(0, Reservation::count());

        // Exactly 1, not 0 — the baseline property fixture migration
        // (2026_10_20_000000) seeds one building/floor/suite/room
        // unconditionally, the same way create_hotels_table seeds a default
        // Hotel row. Neither counts as "demo data" for this assertion's
        // purposes; both are schema-level baseline fixtures, not seeded by
        // db:seed at all.
        $this->assertSame(1, Room::count());
    }
}
