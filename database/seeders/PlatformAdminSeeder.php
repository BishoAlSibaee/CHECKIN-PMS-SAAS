<?php

namespace Database\Seeders;

use App\Models\PlatformAdmin;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class PlatformAdminSeeder extends Seeder
{
    public const EMAIL = 'platform-admin@checkinbooking.local';

    public const PASSWORD = 'ChangeMe123!';

    public function run(): void
    {
        $email = config('hotel.platform_admin_email_override') ?: self::EMAIL;
        $password = config('hotel.platform_admin_password_override') ?: self::PASSWORD;

        PlatformAdmin::updateOrCreate(
            ['email' => $email],
            [
                'name' => 'Platform Operator',
                'password' => Hash::make($password),
                'is_active' => true,
            ]
        );

        $this->command?->info('Platform admin seeded successfully.');
        $this->command?->line('  Email    : '.$email);
        if (!config('hotel.platform_admin_password_override')) {
            $this->command?->line('  Password : '.self::PASSWORD);
        } else {
            $this->command?->line('  Password : (custom — not printed)');
        }
    }
}
