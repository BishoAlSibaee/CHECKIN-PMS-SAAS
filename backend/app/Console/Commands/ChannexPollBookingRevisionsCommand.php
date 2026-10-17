<?php

namespace App\Console\Commands;

use App\Jobs\Channex\ProcessBookingRevisionJob;
use App\Models\ChannexBookingRevision;
use App\Services\Channex\ChannexBookingIngestionService;
use App\Services\Channex\ChannexClient;
use Illuminate\Console\Command;
use Throwable;

/**
 * Mandatory safety net per Channex's own docs — the booking-revisions feed
 * is a 30-minute non-durable window, so relying on the webhook alone risks
 * silently losing a booking if delivery fails even once.
 */
class ChannexPollBookingRevisionsCommand extends Command
{
    protected $signature = 'channex:poll-booking-revisions';

    protected $description = 'Poll the Channex booking-revisions feed for anything the webhook missed';

    public function handle(ChannexClient $client): int
    {
        if (! $client->isEnabled()) {
            return self::SUCCESS;
        }

        try {
            $feed = $client->getBookingRevisionsFeed();
        } catch (Throwable $e) {
            $this->error("Failed to fetch booking revisions feed: {$e->getMessage()}");

            return self::FAILURE;
        }

        $items = (array) ($feed['data'] ?? []);
        $count = 0;

        foreach ($items as $item) {
            $attributes = (array) ($item['attributes'] ?? []);
            $bookingId = (string) ($attributes['booking_id'] ?? '');
            $revisionId = (string) ($attributes['revision_id'] ?? $attributes['id'] ?? '');
            if ($bookingId === '' || $revisionId === '') {
                continue;
            }

            $revision = ChannexBookingRevision::firstOrCreate(
                ['channex_booking_id' => $bookingId, 'channex_revision_id' => $revisionId],
                [
                    'channex_property_id' => $attributes['property_id'] ?? null,
                    // Channex's own docs confirm the feed item's "status"
                    // (new/modified/cancelled) is the same reliable field the
                    // webhook's event name conveys — mapped here into the
                    // same event-hint string the webhook path stores, so a
                    // poll-discovered revision gets exactly as reliable a
                    // cancellation signal as a webhook-delivered one, instead
                    // of leaning entirely on ingest()'s own secondary
                    // fallback (re-deriving it from the full detail fetch).
                    'event' => ChannexBookingIngestionService::mapBookingStatusToEvent($attributes['status'] ?? null),
                    'status' => ChannexBookingRevision::STATUS_PENDING,
                ]
            );

            if ($revision->status === ChannexBookingRevision::STATUS_PENDING) {
                ProcessBookingRevisionJob::dispatch($revision->id);
                $count++;
            }
        }

        $this->info("Processed {$count} previously-unseen revision(s) from the feed.");

        return self::SUCCESS;
    }
}
