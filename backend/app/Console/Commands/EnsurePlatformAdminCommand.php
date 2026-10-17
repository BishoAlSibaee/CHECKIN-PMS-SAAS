<?php

namespace App\Console\Commands;

use Database\Seeders\PlatformAdminSeeder;
use Illuminate\Console\Command;

class EnsurePlatformAdminCommand extends Command
{
    protected $signature = 'platform-admin:ensure {--email= : Set the platform admin email (default: platform-admin@checkinbooking.local)} {--password= : Set a new password}';

    protected $description = 'Create or update the platform admin account used to log into the standalone PlatformAdmin app';

    public function handle(): int
    {
        if ($password = $this->option('password')) {
            if (strlen($password) < 8) {
                $this->error('Password must be at least 8 characters.');

                return self::FAILURE;
            }

            config(['hotel.platform_admin_password_override' => $password]);
        }

        if ($email = $this->option('email')) {
            config(['hotel.platform_admin_email_override' => $email]);
        }

        $this->call('db:seed', ['--class' => PlatformAdminSeeder::class, '--force' => true]);

        $this->info('Platform admin account is ready.');

        return self::SUCCESS;
    }
}
