<?php

namespace App\Console\Commands;

use App\Jobs\Channex\ProcessBookingRevisionJob;
use App\Models\ChannexBookingRevision;
use App\Services\Channex\ChannexBookingIngestionService;
use App\Services\Channex\ChannexClient;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * Manual, time-scoped recovery for post-outage backfill — deliberately NOT
 * scheduled to run periodically (Channex's own guidance: use targeted
 * recovery after a known gap, not a recurring full sweep).
 */
class ChannexBackfillBookingsCommand extends Command
{
    protected $signature = 'channex:backfill-bookings {--since= : ISO timestamp or parseable date; defaults to 24 hours ago}';

    protected $description = 'One-off recovery: pull every Channex booking inserted since a given time';

    public function handle(ChannexClient $client): int
    {
        $since = $this->option('since') ? Carbon::parse($this->option('since')) : Carbon::now()->subDay();

        $response = $client->getBookingsSince($since->toIso8601String());
        $bookings = (array) ($response['data'] ?? []);

        $count = 0;
        foreach ($bookings as $booking) {
            $attributes = (array) ($booking['attributes'] ?? []);
            $bookingId = (string) ($attributes['booking_id'] ?? '');
            $revisionId = (string) ($attributes['revision_id'] ?? '');
            if ($bookingId === '' || $revisionId === '') {
                continue;
            }

            $revision = ChannexBookingRevision::firstOrCreate(
                ['channex_booking_id' => $bookingId, 'channex_revision_id' => $revisionId],
                [
                    'channex_property_id' => $attributes['property_id'] ?? null,
                    // Same reliable status field as the polling command —
                    // see ChannexBookingIngestionService::mapBookingStatusToEvent().
                    'event' => ChannexBookingIngestionService::mapBookingStatusToEvent($attributes['status'] ?? null),
                    'status' => ChannexBookingRevision::STATUS_PENDING,
                ]
            );

            if ($revision->status === ChannexBookingRevision::STATUS_PENDING) {
                ProcessBookingRevisionJob::dispatch($revision->id);
                $count++;
            }
        }

        $this->info("Backfilled {$count} booking(s) inserted since {$since->toIso8601String()}.");

        return self::SUCCESS;
    }
}
