<?php

namespace App\Services\Grms;

use App\Models\GrmsOutboxEvent;
use Illuminate\Support\Str;

/**
 * Writes to the GRMS v1 outbound event queue — see GrmsOutboxEvent and
 * DispatchGrmsOutboxCommand (the actual sender). Call sites are the same
 * reservation lifecycle actions that already call notifyReservationLive()
 * (ReservationController), wired alongside it rather than replacing it —
 * the live-broadcast notification and the GRMS outbox event serve different
 * audiences (staff UI vs. GRMS).
 */
class GrmsOutboxService
{
    /**
     * A pending event for the same entity is replaced by the newer one
     * (contract's own rule) instead of stacking duplicates — only matters
     * while the previous one hasn't been sent yet; once it's `sent` it's
     * left alone and this creates a fresh row.
     *
     * @param  array<string, mixed>  $data
     * @param  int  $delaySeconds  Contract section 5 "Suites": a stay involved
     *   in a suite move needs a few seconds' grace before sending, so that N
     *   sequential per-room moves collapse into one final-state event instead
     *   of sending each half-moved state (the "replace the pending event for
     *   the same entity" rule above does the actual collapsing — this only
     *   delays when that pending row becomes due).
     */
    public function enqueue(
        int $hotelId,
        string $type,
        string $entityType,
        string $entityId,
        int $version,
        array $data,
        int $delaySeconds = 0,
    ): GrmsOutboxEvent {
        $existing = GrmsOutboxEvent::where('hotel_id', $hotelId)
            ->where('entity_type', $entityType)
            ->where('entity_id', $entityId)
            ->where('status', GrmsOutboxEvent::STATUS_PENDING)
            ->first();

        $payload = [
            'id' => $existing->event_uuid ?? (string) Str::uuid(),
            'type' => $type,
            'version' => $version,
            'occurred_at' => now()->toIso8601String(),
            'hotel' => ['pms_id' => (string) $hotelId],
            'data' => $data,
        ];

        if ($existing) {
            $existing->update([
                'type' => $type,
                'version' => $version,
                'payload' => $payload,
                'attempts' => 0,
                'next_attempt_at' => now()->addSeconds($delaySeconds),
                'last_error' => null,
            ]);

            return $existing->fresh();
        }

        return GrmsOutboxEvent::create([
            'event_uuid' => $payload['id'],
            'hotel_id' => $hotelId,
            'type' => $type,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'version' => $version,
            'payload' => $payload,
            'status' => GrmsOutboxEvent::STATUS_PENDING,
            'next_attempt_at' => now()->addSeconds($delaySeconds),
        ]);
    }

    /**
     * Puts a 'review' (retries exhausted) or 'rejected' event back in the
     * send queue from scratch — platform admin's manual retry action, after
     * whatever made it fail (GRMS downtime, a since-fixed room mapping) has
     * been addressed.
     */
    public function requeue(GrmsOutboxEvent $event): void
    {
        $event->update([
            'status' => GrmsOutboxEvent::STATUS_PENDING,
            'attempts' => 0,
            'next_attempt_at' => now(),
            'last_error' => null,
        ]);
    }
}
