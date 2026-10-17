<?php

namespace Database\Seeders;

use App\Models\Hotel;
use App\Models\User;
use App\Models\Permission;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Laravel\Passport\Client;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class AdminSeeder extends Seeder
{
    /**
     * Default admin credentials (change after first login in production).
     */
    public const JOB_NUMBER = '001';
    public const PASSWORD = 'admin123';
    public const EMAIL = 'admin@hotel.com';

    public function run(): void
    {
        $this->ensurePassportPersonalClient();

        // Spatie "teams" scopes roles per hotel (team_foreign_key=hotel_id — see
        // HotelRoleProvisioningService). Console/seeder context never runs
        // ResolveCurrentHotel, so without this every role lookup below is
        // scoped to hotel_id=NULL and silently misses the real admin role.
        $defaultHotelId = (int) (Hotel::query()->orderBy('id')->value('id') ?? 1);
        $registrar = app(PermissionRegistrar::class);
        $previousTeamId = $registrar->getPermissionsTeamId();
        $registrar->setPermissionsTeamId($defaultHotelId);

        try {
            // withoutGlobalScopes(): Spatie's teams scope treats hotel_id=NULL
            // as matching every team, so a plain scoped query here could find
            // a stale orphan row instead of this hotel's real admin role (or
            // falsely report one "exists" and skip seeding it). Explicit
            // hotel_id sidesteps that — see PermissionSeeder::role().
            if (!Schema::hasTable('roles')
                || !Role::withoutGlobalScopes()->where('hotel_id', $defaultHotelId)->where('name', 'admin')->where('guard_name', 'api')->exists()) {
                $this->call(PermissionSeeder::class);
            }

            $adminRole = Role::withoutGlobalScopes()->where('hotel_id', $defaultHotelId)->where('name', 'admin')->where('guard_name', 'api')->first();
            if (!$adminRole) {
                $this->command->error('Admin role not found. PermissionSeeder may have failed.');
                return;
            }

            // Keep admin role synced with every permission (including newly added ones).
            $adminRole->syncPermissions(Permission::where('guard_name', 'api')->get());

            $password = config('hotel.admin_password_override') ?: self::PASSWORD;

            $admin = User::updateOrCreate(
                ['job_number' => self::JOB_NUMBER],
                [
                    'name' => 'Admin',
                    'email' => self::EMAIL,
                    'jobtitle_id' => null,
                    'department_id' => null,
                    'mobile' => '0555555555',
                    'discount_id' => null,
                    'active' => 1,
                    'password' => Hash::make($password),
                ]
            );

            // Login resolves "current hotel" from the hotels a user is attached
            // to (see UsersController::login) — without this the admin account
            // resolves no hotel at all and every role check fails again.
            $admin->hotels()->syncWithoutDetaching([$defaultHotelId]);

            $admin->syncRoles([$adminRole->name]);
            // Role is the single source of truth; drop legacy direct permission rows.
            $admin->syncPermissions([]);

            $registrar->forgetCachedPermissions();

            $this->command->info('Admin user seeded successfully.');
            $this->command->line('  Job number : ' . self::JOB_NUMBER);
            if (!config('hotel.admin_password_override')) {
                $this->command->line('  Password   : ' . self::PASSWORD);
            } else {
                $this->command->line('  Password   : (custom — not printed)');
            }
            $this->command->line('  Email      : ' . self::EMAIL);
        } finally {
            $registrar->setPermissionsTeamId($previousTeamId);
        }
    }

    private function ensurePassportPersonalClient(): void
    {
        if (!Schema::hasTable('oauth_clients')) {
            $this->command->warn('Passport oauth_clients table missing. Run: php artisan migrate');
            return;
        }

        if (Client::query()->where('personal_access_client', true)->exists()) {
            return;
        }

        Artisan::call('passport:client', [
            '--personal' => true,
            '--name' => 'Hotel System Personal Access',
            '--no-interaction' => true,
        ]);

        $this->command->info('Passport personal access client created.');
    }
}
