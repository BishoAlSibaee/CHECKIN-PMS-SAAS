<?php

namespace App\Console\Commands;

use App\Models\KioskDevice;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

class CreateKioskDevice extends Command
{
    protected $signature = 'kiosk:create-device {name : Human-readable device name, e.g. "Lobby Kiosk 1"} {building_id : Building this kiosk serves}';

    protected $description = 'Create a self-service kiosk device and print its one-time bearer token';

    public function handle(): int
    {
        $name = $this->argument('name');
        $buildingId = (int) $this->argument('building_id');

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
