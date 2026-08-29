<?php

namespace App\Console\Commands;

use App\Support\OrphanedInnoDbRepair;
use App\Support\SchemaTableHealth;
use Illuminate\Console\Command;

class RepairOrphanedInnoDbCommand extends Command
{
    protected $signature = 'db:repair-orphaned-tables';

    protected $description = 'Rebuild InnoDB tables that hit MySQL error 1932 (missing from engine)';

    public function handle(OrphanedInnoDbRepair $repair): int
    {
        $before = [];
        foreach (OrphanedInnoDbRepair::REPAIRABLE_TABLES as $table) {
            if (SchemaTableHealth::isOrphanedInEngine($table) || ! SchemaTableHealth::isUsable($table)) {
                $before[] = $table;
            }
        }

        if ($before === []) {
            $this->info('No orphaned InnoDB tables detected.');
        } else {
            $this->warn('Repairing: '.implode(', ', $before));
        }

        $repaired = $repair->repair();

        foreach (OrphanedInnoDbRepair::REPAIRABLE_TABLES as $table) {
            $ok = SchemaTableHealth::isUsable($table);
            $this->line(sprintf('  %s  %s', $ok ? 'OK' : 'FAIL', $table));
            if (! $ok) {
                return self::FAILURE;
            }
        }

        if ($repaired !== []) {
            $this->info('Rebuilt: '.implode(', ', $repaired));
            $this->comment('Reservation transactional data for those tables was already unreadable; child orphans were purged.');
            $this->comment('Optional: php artisan demo:setup --light   (or demo:reset-reservations) to reload demo bookings.');
        } else {
            $this->info('Schema ensured (columns / empty recreates as needed).');
        }

        return self::SUCCESS;
    }
}
