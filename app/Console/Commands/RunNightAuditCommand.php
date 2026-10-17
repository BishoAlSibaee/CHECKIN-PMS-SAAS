<?php

namespace App\Console\Commands;

use App\Models\Hotel;
use App\Services\NightAuditService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Throwable;

class RunNightAuditCommand extends Command
{
    protected $signature = 'night-audit:run
        {--hotel= : Hotel id to audit. Defaults to every active hotel.}
        {--date= : Operational day to audit (Y-m-d). Defaults to yesterday.}';

    protected $description = 'Run the nightly reconciliation (occupancy, books, ZATCA) for one or every hotel';

    public function handle(NightAuditService $service): int
    {
        $date = $this->option('date') ? Carbon::parse($this->option('date')) : Carbon::yesterday();

        $hotelIds = $this->option('hotel')
            ? [(int) $this->option('hotel')]
            : Hotel::where('is_active', true)->pluck('id')->all();

        if ($hotelIds === []) {
            $this->warn('No active hotels to audit.');

            return self::SUCCESS;
        }

        $failures = 0;
        foreach ($hotelIds as $hotelId) {
            try {
                $run = $service->runForHotel((int) $hotelId, $date);
                $this->info("Hotel #{$hotelId} — {$date->toDateString()} — {$run->exceptions_count} exception(s).");
            } catch (Throwable $e) {
                $failures++;
                $this->error("Hotel #{$hotelId} — audit failed: {$e->getMessage()}");
            }
        }

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }
}
