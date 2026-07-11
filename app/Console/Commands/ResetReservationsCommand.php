<?php

namespace App\Console\Commands;

use App\Services\ReservationDataCleaner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

class ResetReservationsCommand extends Command
{
    protected $signature = 'demo:reset-reservations
                            {--no-seed : Purge only; do not seed the 6 test bookings}
                            {--no-journal : Skip accounting:backfill-journal after seeding}
                            {--force : Skip confirmation prompt}';

    protected $description = 'Purge all reservations (keep users/RBAC/master data) and seed 6 test bookings (Jul/Aug 2026)';

    public function handle(ReservationDataCleaner $cleaner): int
    {
        if (!$this->option('force') && !$this->confirm('Delete ALL reservations and related financial data? Users and permissions are kept.', true)) {
            $this->warn('Aborted.');

            return self::FAILURE;
        }

        $this->info('Purging reservation data...');
        $counts = $cleaner->purge();
        $this->table(['Table / action', 'Rows affected'], collect($counts)->map(fn ($n, $k) => [$k, $n])->values()->all());

        if ($this->option('no-seed')) {
            $this->newLine();
            $this->info('Purge complete. Database is empty of reservations and financial data (no seed).');

            return self::SUCCESS;
        }

        $this->info('Seeding 3 reservations per month (July + August 2026)...');
        Artisan::call('db:seed', [
            '--class' => 'Database\\Seeders\\MinimalJulAugReservationSeeder',
            '--force' => true,
            '--no-interaction' => true,
        ]);
        $this->line(trim(Artisan::output()));

        if (!$this->option('no-journal')) {
            $this->info('Backfilling journal entries...');
            Artisan::call('accounting:backfill-journal', ['--no-interaction' => true]);
            $this->line(trim(Artisan::output()));
        }

        $this->newLine();
        $this->info('Done. Test filters:');
        $this->line('  July 2026   → 2026-07-01 .. 2026-07-31');
        $this->line('  August 2026 → 2026-08-01 .. 2026-08-31');

        return self::SUCCESS;
    }
}
