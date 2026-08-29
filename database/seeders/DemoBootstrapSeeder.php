<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;

/**
 * Post-seed demo bootstrap: deterministic 2026 scenarios, daily charges, GL journal.
 *
 * Runs from demo:setup after HotelDemoSeeder (or light demo).
 */
class DemoBootstrapSeeder extends Seeder
{
    public function run(): void
    {
        putenv('DEMO_SEED=1');
        $_ENV['DEMO_SEED'] = '1';

        $this->command?->info('Ensuring demo pricing plan dates (mixed mode Aug 2026)...');
        $this->call(DemoPricingFixSeeder::class);

        $this->command?->info('Seeding demo reservation scenarios (2026)...');
        $this->call(ReservationTestDataSeeder::class);

        $this->command?->info('Backfilling reservation daily charges...');
        Artisan::call('reservations:backfill-daily-charges', ['--sync-base' => true]);
        if ($output = trim(Artisan::output())) {
            $this->command?->line($output);
        }

        $this->command?->info('Backfilling accounting journal entries (payments + accrual)...');
        Artisan::call('accounting:backfill-journal');
        if ($output = trim(Artisan::output())) {
            $this->command?->line($output);
        }

        $this->command?->info('Demo bootstrap complete. Verify with: php artisan demo:verify');
    }
}
