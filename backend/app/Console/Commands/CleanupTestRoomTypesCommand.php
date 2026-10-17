<?php

namespace App\Console\Commands;

use App\Models\RoomType;
use App\Services\TestRoomTypeCleanupService;
use Illuminate\Console\Command;

class CleanupTestRoomTypesCommand extends Command
{
    protected $signature = 'room-types:cleanup-test-data
                            {--dry-run : List orphan test room types without deleting}';

    protected $description = 'Remove orphan room types created by PHPUnit/scripts (no assigned rooms)';

    public function handle(TestRoomTypeCleanupService $cleanup): int
    {
        $service = $cleanup;
        $dryRun = (bool) $this->option('dry-run');

        $candidates = RoomType::query()
            ->where(function ($query) use ($service) {
                foreach (TestRoomTypeCleanupService::TEST_NAME_PREFIXES as $prefix) {
                    $query->orWhere('name_en', 'like', $prefix . '%');
                }
            })
            ->whereDoesntHave('rooms')
            ->orderBy('id')
            ->get(['id', 'name_en', 'name_ar']);

        if ($candidates->isEmpty()) {
            $this->info('No orphan test room types found.');

            return self::SUCCESS;
        }

        $this->line('Orphan test room types: ' . $candidates->count());

        if ($dryRun) {
            foreach ($candidates as $type) {
                $this->line("  #{$type->id} {$type->name_en}");
            }

            return self::SUCCESS;
        }

        $stats = $service->purgeOrphanTestRoomTypes();

        $this->info(sprintf(
            'Removed %d room type(s), %d pricing link(s), %d pricing plan(s). Skipped in-use: %d.',
            $stats['deleted_types'],
            $stats['deleted_links'],
            $stats['deleted_plans'],
            $stats['skipped_in_use']
        ));

        $remaining = RoomType::count();
        $this->line("Remaining room types: {$remaining}");

        return self::SUCCESS;
    }
}
