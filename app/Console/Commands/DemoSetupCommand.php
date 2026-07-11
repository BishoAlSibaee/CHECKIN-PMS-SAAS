<?php

namespace App\Console\Commands;

use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\DemoBootstrapSeeder;
use Database\Seeders\HotelDemoSeeder;
use Database\Seeders\RefundPolicySeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

class DemoSetupCommand extends Command
{
    protected $signature = 'demo:setup
                            {--fresh : Run migrate:fresh before seeding}
                            {--light : Skip HotelDemoSeeder (~650 reservations); keep test scenarios only}';

    protected $description = 'Load demo property + reservations on top of essential seed data';

    public function handle(): int
    {
        if ($this->option('fresh')) {
            $this->info('Running migrate:fresh...');
            Artisan::call('migrate:fresh', ['--force' => true]);
            $this->line(Artisan::output());
        }

        $this->info('Seeding essential data (permissions, admin, reference lookups)...');
        Artisan::call('db:seed', ['--force' => true]);
        $this->line(Artisan::output());

        $this->info('Seeding finance prerequisites (chart of accounts, refund policies)...');
        Artisan::call('db:seed', [
            '--class' => ChartOfAccountsSeeder::class,
            '--force' => true,
        ]);
        Artisan::call('db:seed', [
            '--class' => RefundPolicySeeder::class,
            '--force' => true,
        ]);

        if ($this->option('light')) {
            $this->info('Light demo — skipping HotelDemoSeeder.');
        } else {
            $this->info('Seeding full demo property (~650 reservations)...');
            Artisan::call('db:seed', [
                '--class' => HotelDemoSeeder::class,
                '--force' => true,
            ]);
            $this->line(Artisan::output());
        }

        $this->info('Running demo bootstrap (test scenarios, daily charges, journal)...');
        Artisan::call('db:seed', [
            '--class' => DemoBootstrapSeeder::class,
            '--force' => true,
        ]);
        $this->line(Artisan::output());

        return $this->call('demo:verify');
    }
}
