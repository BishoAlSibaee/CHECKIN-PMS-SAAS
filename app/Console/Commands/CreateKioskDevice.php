<?php

namespace App\Console\Commands;

use App\Models\Building;
use App\Models\KioskDevice;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class CreateKioskDevice extends Command
{
    protected $signature = 'kiosk:create-device {name : Human-readable device name, e.g. "Lobby Kiosk 1"} {building_id : Building this kiosk serves}';

    protected $description = 'Create a self-service kiosk device and print its one-time bearer token';

    public function handle(): int
    {
        $name = $this->argument('name');
        $buildingId = (int) $this->argument('building_id');

        $building = Building::find($buildingId);
        if (!$building) {
            $this->error("Building {$buildingId} not found.");

            return self::FAILURE;
        }

        $serviceUser = User::firstOrCreate(
            ['job_number' => 'KIOSK-SVC'],
            [
                'name' => 'Kiosk Service Account',
                'password' => Str::random(40),
                'mobile' => '0000000000',
                'email' => 'kiosk-service@localhost.invalid',
                'active' => 1,
            ]
        );

        // One shared service account can run kiosks across multiple hotels —
        // Spatie "teams" (team_foreign_key=hotel_id) scopes role assignment per
        // hotel, so it needs the role (and hotel_user access) for THIS specific
        // building's hotel, not just once globally.
        $serviceUser->hotels()->syncWithoutDetaching([$building->hotel_id]);
        app(PermissionRegistrar::class)->setPermissionsTeamId($building->hotel_id);

        $kioskRole = Role::where('name', 'kiosk')->where('guard_name', 'api')->first();
        if (!$kioskRole) {
            $this->error("Role 'kiosk' not found. Run: php artisan db:seed --class=PermissionSeeder");

            return self::FAILURE;
        }

        if (!$serviceUser->hasRole($kioskRole)) {
            $serviceUser->assignRole($kioskRole);
        }

        $token = Str::random(40);

        $device = KioskDevice::create([
            'name' => $name,
            'building_id' => $buildingId,
            'user_id' => $serviceUser->id,
            'token_hash' => hash('sha256', $token),
            'active' => true,
        ]);

        $this->info("Kiosk device '{$device->name}' created (id={$device->id}).");
        $this->warn('Bearer token (shown once, store it securely on the kiosk device — it cannot be retrieved again):');
        $this->line($token);

        return self::SUCCESS;
    }
}
