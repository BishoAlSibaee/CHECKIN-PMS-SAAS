<?php

namespace App\Console\Commands;

use App\Models\GrmsHotelCredential;
use App\Models\GrmsOutboxEvent;
use App\Services\Grms\GrmsSignatureService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Sends due GRMS v1 outbox events (see GrmsOutboxService::enqueue()), up to
 * 50 per hotel per request, matching the contract's envelope. Scheduled
 * every minute — see Kernel::schedule(). Retry backoff on failure follows
 * GrmsOutboxEvent::RETRY_DELAYS_SECONDS exactly; once exhausted an event
 * moves to 'review' for a human to look at (surfaced in Platform Admin).
 */
class DispatchGrmsOutboxCommand extends Command
{
    protected $signature = 'grms:dispatch-outbox';

    protected $description = 'Send due GRMS v1 outbox events to GRMS, honoring the contract retry schedule.';

    public function handle(GrmsSignatureService $signer): int
    {
        if (!config('grms.enabled')) {
            return self::SUCCESS;
        }

        $dueHotelIds = GrmsOutboxEvent::where('status', GrmsOutboxEvent::STATUS_PENDING)
            ->where(function ($q) {
                $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now());
            })
            ->distinct()
            ->pluck('hotel_id');

        foreach ($dueHotelIds as $hotelId) {
            $this->dispatchForHotel((int) $hotelId, $signer);
        }

        return self::SUCCESS;
    }

    private function dispatchForHotel(int $hotelId, GrmsSignatureService $signer): void
    {
        $credential = GrmsHotelCredential::where('hotel_id', $hotelId)
            ->where('direction', GrmsHotelCredential::DIRECTION_OUTBOUND)
            ->where('is_active', true)
            ->first();

        if (!$credential) {
            Log::warning('GRMS outbox: no active outbound credential, skipping hotel', ['hotel_id' => $hotelId]);

            return;
        }

        $events = GrmsOutboxEvent::where('hotel_id', $hotelId)
            ->where('status', GrmsOutboxEvent::STATUS_PENDING)
            ->where(function ($q) {
                $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now());
            })
            ->orderBy('id')
            ->limit(50)
            ->get();

        if ($events->isEmpty()) {
            return;
        }

        $body = json_encode(['events' => $events->pluck('payload')->values()->all()]);
        $timestamp = (string) time();
        $signature = $signer->sign($timestamp, $body, $credential->secret);

        try {
            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
                'X-Key-Id' => $credential->key_id,
                'X-Timestamp' => $timestamp,
                'X-Signature' => $signature,
            ])->withBody($body, 'application/json')
                ->timeout((int) config('grms.timeout', 10))
                ->post(rtrim((string) config('grms.base_url'), '/').'/pms/v1/events');
        } catch (\Throwable $e) {
            $this->markFailedBatch($events, $e->getMessage());

            return;
        }

        if (!$response->successful()) {
            $status = $response->status();
            $error = 'HTTP '.$status.': '.$response->body();

            // Contract section 4: "a 4xx other than 408/429 is not repeated
            // (rejected)" — retrying with the same bad credential/payload for
            // up to 6 hours is pointless; go straight to the review queue.
            if ($status >= 400 && $status < 500 && !in_array($status, [408, 429], true)) {
                $this->markTerminallyRejected($events, $error);
            } else {
                $this->markFailedBatch($events, $error);
            }

            return;
        }

        $results = $response->json('results', []);
        foreach ($events as $index => $event) {
            $outcome = $results[$index]['result'] ?? $results[$index]['status'] ?? null;
            $this->applyResult($event, $outcome, $results[$index]['reason'] ?? null);
        }
    }

    private function applyResult(GrmsOutboxEvent $event, ?string $outcome, ?string $reason): void
    {
        match ($outcome) {
            'accepted', 'duplicate' => $event->update(['status' => GrmsOutboxEvent::STATUS_ACCEPTED]),
            'stale' => $event->update(['status' => GrmsOutboxEvent::STATUS_STALE]),
            'rejected' => $event->update(['status' => GrmsOutboxEvent::STATUS_REJECTED, 'last_error' => $reason]),
            default => $this->markFailedBatch(collect([$event]), 'Unrecognized result: '.json_encode($outcome)),
        };
    }

    private function markFailedBatch($events, string $error): void
    {
        foreach ($events as $event) {
            $attempts = $event->attempts + 1;
            $delays = GrmsOutboxEvent::RETRY_DELAYS_SECONDS;

            if ($attempts > count($delays)) {
                $event->update([
                    'status' => GrmsOutboxEvent::STATUS_REVIEW,
                    'attempts' => $attempts,
                    'last_error' => $error,
                ]);

                continue;
            }

            $event->update([
                'attempts' => $attempts,
                'next_attempt_at' => now()->addSeconds($delays[$attempts - 1]),
                'last_error' => $error,
            ]);
        }
    }

    /** A hard 4xx (bad credential, malformed payload) — retrying won't help, so skip straight to review. */
    private function markTerminallyRejected($events, string $error): void
    {
        foreach ($events as $event) {
            $event->update([
                'status' => GrmsOutboxEvent::STATUS_REVIEW,
                'attempts' => $event->attempts + 1,
                'last_error' => $error,
            ]);
        }
    }
}
