<?php

namespace App\Console\Commands;

use App\Models\GrmsOutboxEvent;
use App\Services\Grms\GrmsOutboxDispatcher;
use Illuminate\Console\Command;

/**
 * Safety net for due GRMS v1 outbox events — catches retries, anything a
 * queue worker outage missed, or an event whose own debounce delay
 * (suites) hadn't elapsed when DispatchGrmsOutboxForHotelJob tried it.
 * Scheduled every minute (see Kernel::schedule()); the near-immediate path
 * is DispatchGrmsOutboxForHotelJob, queued right after
 * GrmsOutboxService::enqueue(). Both just call
 * GrmsOutboxDispatcher::dispatchForHotel() — no duplicated HTTP/retry logic.
 */
class DispatchGrmsOutboxCommand extends Command
{
    protected $signature = 'grms:dispatch-outbox';

    protected $description = 'Send due GRMS v1 outbox events to GRMS, honoring the contract retry schedule.';

    public function handle(GrmsOutboxDispatcher $dispatcher): int
    {
        $dueHotelIds = GrmsOutboxEvent::where('status', GrmsOutboxEvent::STATUS_PENDING)
            ->where(function ($q) {
                $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now());
            })
            ->distinct()
            ->pluck('hotel_id');

        foreach ($dueHotelIds as $hotelId) {
            $dispatcher->dispatchForHotel((int) $hotelId);
        }

        return self::SUCCESS;
    }
}
