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
        $this->assertSame(0, Room::count());
    }
}
