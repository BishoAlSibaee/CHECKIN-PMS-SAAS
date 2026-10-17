<?php

namespace App\Jobs\Channex;

use App\Models\ChannexBookingRevision;
use App\Services\Channex\ChannexBookingIngestionService;
use App\Services\Channex\ChannexClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Pulls one booking revision's full detail, converts it to a reservation,
 * and acknowledges it with Channex. Ack failure and ingestion failure are
 * tracked as distinct statuses since a processed-but-unacked booking must
 * only be retried on the ack call, not re-ingested (that would double-book).
 */
class ProcessBookingRevisionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(private readonly int $revisionRowId) {}

    public function backoff(): array
    {
        return [30, 120, 600];
    }

    public function handle(ChannexClient $client, ChannexBookingIngestionService $ingestionService): void
    {
        $revision = ChannexBookingRevision::find($this->revisionRowId);
        if ($revision === null || $revision->status === ChannexBookingRevision::STATUS_PROCESSED) {
            return;
        }

        try {
            $detail = $client->getBookingRevision($revision->channex_revision_id);
            $booking = (array) ($detail['data']['attributes'] ?? []);

            $reservation = $ingestionService->ingest($booking, $revision->event);

            $revision->update([
                'status' => ChannexBookingRevision::STATUS_PROCESSED,
                'reservation_id' => $reservation?->id,
                'raw_payload' => $booking,
                'processed_at' => now(),
                'error_message' => null,
            ]);
        } catch (Throwable $e) {
            $revision->update([
                'status' => ChannexBookingRevision::STATUS_FAILED,
                'error_message' => $e->getMessage(),
            ]);
            Log::warning('Channex booking ingestion failed', [
                'revision_id' => $revision->channex_revision_id,
                'error' => $e->getMessage(),
            ]);

            throw $e; // let the queue retry per $tries/backoff
        }

        try {
            $client->ackBookingRevision($revision->channex_revision_id);
        } catch (Throwable $e) {
            $revision->update(['status' => ChannexBookingRevision::STATUS_ACK_FAILED]);
            Log::warning('Channex booking ack failed', [
                'revision_id' => $revision->channex_revision_id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
