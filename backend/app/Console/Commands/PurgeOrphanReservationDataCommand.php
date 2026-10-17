<?php

namespace App\Console\Commands;

use App\Support\ReservationRelatedCleanup;
use Illuminate\Console\Command;

class PurgeOrphanReservationDataCommand extends Command
{
    protected $signature = 'reservations:purge-orphans
                            {--dry-run : List orphan reservation IDs without deleting}';

    protected $description = 'Delete payments, charges, journal entries, taxes, and logs that still reference missing reservations';

    public function handle(): int
    {
        $orphanIds = ReservationRelatedCleanup::collectMissingReservationIdsReferenced();
        $orphanLines = ReservationRelatedCleanup::countOrphanJournalLines();

        if ($orphanIds->isEmpty() && $orphanLines === 0) {
            $this->info('No orphan reservation-related data found.');

            return self::SUCCESS;
        }

        $this->warn(sprintf(
            'Found %d missing reservation id(s) still referenced, and %d orphan journal line(s).',
            $orphanIds->count(),
            $orphanLines
        ));

        if ($orphanIds->isNotEmpty()) {
            $this->line('IDs: '.$orphanIds->take(50)->implode(', ').($orphanIds->count() > 50 ? '…' : ''));
        }

        if ($this->option('dry-run')) {
            $this->comment('Dry run only — nothing deleted.');

            return self::SUCCESS;
        }

        $counts = ReservationRelatedCleanup::purgeOrphansForMissingReservations();

        foreach ($counts as $key => $value) {
            $this->line(sprintf('  %s: %s', $key, $value));
        }

        $this->info('Orphan reservation-related data purged.');

        return self::SUCCESS;
    }
}
