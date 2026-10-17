<?php

namespace App\Http\Controllers\Channex;

use App\Http\Controllers\Controller;
use App\Jobs\Channex\ProcessBookingRevisionJob;
use App\Models\ChannexBookingRevision;
use App\Models\ChannexProperty;
use App\Services\Channex\ChannexReservationRequestService;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Receives Channex's lightweight booking-event notification and returns 200
 * immediately — the actual pull-and-ingest happens in a queued job, since
 * Channex's own docs warn the booking-revisions feed is a 30-minute
 * non-durable window and this endpoint must not risk holding up their retry.
 */
class ChannexWebhookController extends Controller
{
    public function __construct(
        private ChannexReservationRequestService $reservationRequestService,
        private NotificationService $notifications,
    ) {}

    public function handle(Request $request)
    {
        $secret = (string) config('channex.webhook_secret');
        if ($secret !== '' && $request->header('X-Channex-Webhook-Secret') !== $secret) {
            return response()->json(['message' => 'Invalid webhook secret'], Response::HTTP_UNAUTHORIZED);
        }

        $event = (string) $request->input('event');
        $payload = (array) $request->input('payload', []);
        $propertyId = (string) ($request->input('property_id') ?? $payload['property_id'] ?? '');

        // Channex's own proactive warning that a booking has sat unacknowledged
        // for its full 30-minute non-durable window — the closest thing to "we
        // are about to permanently lose a paid booking" this integration gets.
        // Handled before the booking_* filter below since this event name
        // doesn't start with "booking_".
        if ($event === 'non_acked_booking') {
            return $this->handleNonAckedBooking($payload);
        }

        // Airbnb's "Request to Book": a guest's request, not yet a booking —
        // must be explicitly accepted/declined within 24h. Accepting only
        // tells Airbnb to try charging the guest; the actual reservation
        // still arrives later as an ordinary booking_new, handled below like
        // any other booking.
        if ($event === 'reservation_request') {
            return $this->handleReservationRequest($payload, $propertyId);
        }

        if (in_array($event, ['accepted_reservation', 'declined_reservation'], true)) {
            // Purely an ack that OUR decision (made via the resolve API
            // above) reached Airbnb — we already updated our own record
            // synchronously when we made that call, so there's nothing left
            // to do here beyond a visibility log.
            Log::info('Channex reservation-request decision acknowledged by Airbnb', ['event' => $event, 'payload' => $payload]);

            return response()->json(['message' => 'Accepted'], Response::HTTP_OK);
        }

        if (in_array($event, ['sync_error', 'sync_warning', 'rate_error'], true)) {
            return $this->handleSyncIssue($event, $payload, $propertyId);
        }

        if (in_array($event, ['booking_unmapped_room', 'booking_unmapped_rate'], true)) {
            return $this->handleUnmappedBooking($event, $payload, $propertyId);
        }

        if ($event === 'message') {
            return $this->handleGuestMessage($payload, $propertyId);
        }

        if (in_array($event, ['review', 'updated_review'], true)) {
            return $this->handleReview($event, $payload, $propertyId);
        }

        if (! in_array($event, ['booking_new', 'booking_modification', 'booking_cancellation'], true)) {
            // ari/alteration_request/inquiry/etc. — not this integration's
            // concern yet.
            return response()->json(['message' => 'Ignored'], Response::HTTP_OK);
        }

        $bookingId = (string) ($payload['booking_id'] ?? '');
        $revisionId = (string) ($payload['revision_id'] ?? '');
        if ($bookingId === '' || $revisionId === '') {
            return response()->json(['message' => 'Missing booking_id/revision_id'], Response::HTTP_OK);
        }

        $this->dispatchRevisionIfPending($bookingId, $revisionId, $event, $payload['property_id'] ?? null);

        return response()->json(['message' => 'Accepted'], Response::HTTP_OK);
    }

    /**
     * Channex fires this when a booking revision has sat unacknowledged for
     * its full 30-minute non-durable window — by the time we hear about it,
     * the window may already be gone for good, but we log it as a critical,
     * investigate-immediately signal either way (something upstream — our
     * server, the queue, network — failed to ack in time) and still make a
     * best-effort attempt to pull and ingest it, in case it isn't too late.
     *
     * @param  array<string, mixed>  $payload
     */
    private function handleNonAckedBooking(array $payload): JsonResponse
    {
        $bookingId = (string) ($payload['booking_id'] ?? '');
        $revisionId = (string) ($payload['booking_revision_id'] ?? '');

        Log::critical('Channex booking unacknowledged past its 30-minute window — at risk of being permanently lost', [
            'booking_id' => $bookingId,
            'booking_revision_id' => $revisionId,
            'property_id' => $payload['property_id'] ?? null,
            'channel_id' => $payload['channel_id'] ?? null,
            'ota_code' => $payload['ota_code'] ?? null,
            'customer_name' => $payload['customer_name'] ?? null,
            'arrival_date' => $payload['arrival_date'] ?? null,
            'amount' => $payload['amount'] ?? null,
            'currency' => $payload['currency'] ?? null,
        ]);

        if ($bookingId === '' || $revisionId === '') {
            return response()->json(['message' => 'Accepted (logged; missing ids for a recovery attempt)'], Response::HTTP_OK);
        }

        $this->dispatchRevisionIfPending($bookingId, $revisionId, 'non_acked_booking', $payload['property_id'] ?? null);

        return response()->json(['message' => 'Accepted'], Response::HTTP_OK);
    }

    /** @param  array<string, mixed>  $payload */
    private function handleReservationRequest(array $payload, string $propertyId): JsonResponse
    {
        try {
            $this->reservationRequestService->handleIncoming($payload, $propertyId);
        } catch (\Throwable $e) {
            // Never a 500 back to Channex for the same reason as the booking
            // path — a retry wouldn't fix an unmapped property/missing id
            // anyway, and this is loud enough (report()) to investigate.
            report($e);
        }

        return response()->json(['message' => 'Accepted'], Response::HTTP_OK);
    }

    /**
     * These three events are Channex's own signal that something WE pushed
     * (availability, rates, restrictions) was rejected or flagged by the
     * OTA — without this, a failed price/availability update fails silently
     * and the platform just keeps showing stale figures to guests, which can
     * mean a wrong price honored at check-in or a double-booking from an
     * availability count that never actually reached the OTA.
     *
     * Channex's docs confirm sync_error's payload shape (channel,
     * channel_name, error_type, property_name) but don't document
     * sync_warning/rate_error's fields — handled defensively so an
     * unexpected/missing field never turns this into a 500 back to Channex.
     *
     * @param  array<string, mixed>  $payload
     */
    private function handleSyncIssue(string $event, array $payload, string $propertyId): JsonResponse
    {
        $channel = (string) ($payload['channel_name'] ?? $payload['channel'] ?? 'OTA');
        $errorType = (string) ($payload['error_type'] ?? '');

        $logMethod = $event === 'sync_error' ? 'critical' : 'warning';
        Log::{$logMethod}("Channex {$event}", [
            'event' => $event,
            'property_id' => $propertyId,
            'channel' => $channel,
            'error_type' => $errorType,
            'payload' => $payload,
        ]);

        try {
            $property = ChannexProperty::where('channex_property_id', $propertyId)->first();

            $this->notifications->notify(
                $property?->hotel_id,
                $event,
                'NOTIFICATIONS.CHANNEX_'.strtoupper($event).'_TITLE',
                ['channel' => $channel],
                '/admin/channex-connection',
                'manage ota allocation'
            );
        } catch (\Throwable $e) {
            // A missing/unmapped property must never block acking this
            // webhook — the notification is best-effort visibility, not the
            // point of this handler.
            report($e);
        }

        return response()->json(['message' => 'Accepted'], Response::HTTP_OK);
    }

    /**
     * Channex's own docs flag this as a critical mapping gap requiring rapid
     * resolution: a real guest booked a room type or rate plan on the OTA
     * side that Channex cannot match to anything in our room-type/rate-plan
     * mapping tables, so it can never be ingested as a reservation until
     * staff fix the mapping — risking an overbooking (the OTA still thinks
     * that inventory is sold; our own system never blocks it). Per Channex's
     * docs, booking_unmapped_room mutes booking_unmapped_rate for the same
     * booking (an unmapped room type implies the rate can't be mapped
     * either), so these never double-report the same booking.
     *
     * The payload only ever carries booking_id/booking_revision_id — no
     * useful room/rate identifier to show (that's the whole problem: Channex
     * itself doesn't know what it is either) — so the notification is
     * necessarily just "go check your mapping", not "fix mapping X".
     *
     * @param  array<string, mixed>  $payload
     */
    private function handleUnmappedBooking(string $event, array $payload, string $propertyId): JsonResponse
    {
        $bookingId = (string) ($payload['booking_id'] ?? '');

        Log::critical("Channex {$event} — a booking cannot be matched to an existing mapping", [
            'event' => $event,
            'booking_id' => $bookingId,
            'booking_revision_id' => $payload['booking_revision_id'] ?? null,
            'property_id' => $propertyId,
        ]);

        try {
            $property = ChannexProperty::where('channex_property_id', $propertyId)->first();

            $this->notifications->notify(
                $property?->hotel_id,
                $event,
                $event === 'booking_unmapped_room'
                    ? 'NOTIFICATIONS.CHANNEX_UNMAPPED_ROOM_TITLE'
                    : 'NOTIFICATIONS.CHANNEX_UNMAPPED_RATE_TITLE',
                ['booking_id' => $bookingId],
                '/admin/channex-connection',
                'manage ota allocation'
            );
        } catch (\Throwable $e) {
            report($e);
        }

        return response()->json(['message' => 'Accepted'], Response::HTTP_OK);
    }

    /**
     * A guest message arriving through the OTA's own chat system (Booking.com,
     * Expedia, or Airbnb — the only three Channex supports) — normally
     * invisible to us entirely (staff would have to separately check each
     * platform's own dashboard). This now links straight to the messages
     * inbox page for replying in-system. Only guest-originated messages
     * notify — our own outgoing replies also arrive here with sender=host
     * and would otherwise notify staff about their own message.
     *
     * @param  array<string, mixed>  $payload
     */
    private function handleGuestMessage(array $payload, string $propertyId): JsonResponse
    {
        $sender = (string) ($payload['sender'] ?? '');
        if ($sender !== 'guest') {
            return response()->json(['message' => 'Ignored'], Response::HTTP_OK);
        }

        $text = trim((string) ($payload['message'] ?? ''));
        $preview = mb_strlen($text) > 80 ? mb_substr($text, 0, 80).'…' : $text;

        Log::info('Channex guest message received', [
            'property_id' => $propertyId,
            'booking_id' => $payload['booking_id'] ?? null,
            'message_thread_id' => $payload['message_thread_id'] ?? null,
            'have_attachment' => $payload['have_attachment'] ?? false,
        ]);

        try {
            $property = ChannexProperty::where('channex_property_id', $propertyId)->first();

            $this->notifications->notify(
                $property?->hotel_id,
                'guest_message',
                'NOTIFICATIONS.CHANNEX_GUEST_MESSAGE_TITLE',
                ['preview' => $preview !== '' ? $preview : '(attachment)'],
                '/admin/channex-messages',
                'manage channex messages'
            );
        } catch (\Throwable $e) {
            report($e);
        }

        return response()->json(['message' => 'Accepted'], Response::HTTP_OK);
    }

    /**
     * updated_review "uses the identical payload structure" as review per
     * Channex's docs, distinguished only by the event name — handled
     * identically here, just with a different notification title so staff
     * can tell a brand-new review from a guest editing theirs.
     *
     * @param  array<string, mixed>  $payload
     */
    private function handleReview(string $event, array $payload, string $propertyId): JsonResponse
    {
        $ota = (string) ($payload['ota'] ?? 'OTA');
        $score = $payload['overall_score'] ?? null;

        Log::info("Channex {$event}", [
            'property_id' => $propertyId,
            'booking_id' => $payload['booking_id'] ?? null,
            'ota' => $ota,
            'overall_score' => $score,
        ]);

        try {
            $property = ChannexProperty::where('channex_property_id', $propertyId)->first();

            $this->notifications->notify(
                $property?->hotel_id,
                $event,
                $event === 'review'
                    ? 'NOTIFICATIONS.CHANNEX_NEW_REVIEW_TITLE'
                    : 'NOTIFICATIONS.CHANNEX_REVIEW_UPDATED_TITLE',
                ['channel' => $ota, 'score' => $score ?? '—'],
                '/admin/channex-reviews',
                'manage channex reviews'
            );
        } catch (\Throwable $e) {
            report($e);
        }

        return response()->json(['message' => 'Accepted'], Response::HTTP_OK);
    }

    private function dispatchRevisionIfPending(string $bookingId, string $revisionId, string $event, ?string $propertyId): void
    {
        $revision = ChannexBookingRevision::firstOrCreate(
            ['channex_booking_id' => $bookingId, 'channex_revision_id' => $revisionId],
            [
                'event' => $event,
                'channex_property_id' => $propertyId,
                'status' => ChannexBookingRevision::STATUS_PENDING,
            ]
        );

        if ($revision->status === ChannexBookingRevision::STATUS_PENDING) {
            try {
                // With QUEUE_CONNECTION=sync this runs inline — a failure here
                // must not turn into a 500 for Channex (it would just retry
                // the same notification). The job already records its own
                // failure on the revision row; a real queue driver would also
                // get real async retries via the job's $tries/backoff.
                ProcessBookingRevisionJob::dispatch($revision->id);
            } catch (\Throwable $e) {
                report($e);
            }
        }
    }
}
