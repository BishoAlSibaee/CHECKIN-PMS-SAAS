<?php

namespace App\Console\Commands;

use App\Models\ChannexReservationRequest;
use App\Services\Channex\ChannexReservationRequestService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Airbnb gives a hotel exactly 24h to answer a "Request to Book" — after
 * that it's effectively a decline (dates stay blocked rather than
 * releasing). This applies each hotel's configured fallback (accept/decline)
 * slightly ahead of that deadline so our own resolve call reaches Channex
 * before Airbnb times it out on its own terms instead of ours.
 */
class ChannexResolveExpiringAirbnbRequestsCommand extends Command
{
    protected $signature = 'channex:resolve-expiring-airbnb-requests';

    protected $description = 'Apply each hotel\'s configured fallback action to Airbnb reservation requests about to hit their 24h deadline';

    /** Resolve slightly early so our own call beats Airbnb's own timeout, not races it. */
    private const SAFETY_MARGIN_MINUTES = 10;

    public function handle(ChannexReservationRequestService $service): int
    {
        $pending = ChannexReservationRequest::where('status', ChannexReservationRequest::STATUS_PENDING)->get();
        $resolvedCount = 0;

        foreach ($pending as $request) {
            if (now()->greaterThanOrEqualTo($request->deadline()->subMinutes(self::SAFETY_MARGIN_MINUTES))) {
                try {
                    $service->applyFallback($request);
                    $resolvedCount++;
                } catch (\Throwable $e) {
                    // Leave it pending for the next run rather than losing track of
                    // it — a transient Channex failure here shouldn't silently drop
                    // a request that's about to blow past its real deadline anyway.
                    report($e);
                    Log::critical('Channex Airbnb request fallback resolution failed near its 24h deadline', [
                        'channex_reservation_request_id' => $request->id,
                        'deadline' => $request->deadline()->toIso8601String(),
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        $this->info("Applied fallback to {$resolvedCount} expiring Airbnb request(s).");

        return self::SUCCESS;
    }
}
