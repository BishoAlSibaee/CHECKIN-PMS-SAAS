<?php

namespace App\Console\Commands;

use Database\Seeders\AdminSeeder;
use Illuminate\Console\Command;

class EnsureAdminCommand extends Command
{
    protected $signature = 'hotel:ensure-admin {--password= : Set a new password for job 001 (production)}';

    protected $description = 'Restore job number 001 admin role and sync all API permissions';

    public function handle(): int
    {
        if ($password = $this->option('password')) {
            if (strlen($password) < 8) {
                $this->error('Password must be at least 8 characters.');

                return self::FAILURE;
            }

            config(['hotel.admin_password_override' => $password]);
        }

        $this->call('db:seed', ['--class' => AdminSeeder::class, '--force' => true]);

        $this->info('Admin account 001 is restored with the admin role and full permissions.');
        if ($this->option('password')) {
            $this->info('Password for job 001 was updated.');
        }
        $this->line('Log out and log in again in the Angular app to refresh your session.');

        return self::SUCCESS;
    }
}
