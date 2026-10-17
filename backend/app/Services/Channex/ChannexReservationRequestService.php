<?php

namespace App\Services\Channex;

use App\Models\ChannexProperty;
use App\Models\ChannexReservationRequest;
use App\Models\HotelAirbnbRequestSetting;
use App\Services\HotelLivePublisher;
use App\Services\NotificationService;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Handles Airbnb's "Request to Book" pre-booking handshake (Channex events
 * reservation_request / accepted_reservation / declined_reservation).
 * Deliberately NOT the same thing as creating a reservation — per Channex's
 * docs, accepting only tells Airbnb to try charging the guest and
 * re-confirming availability; the actual booking arrives later as an
 * ordinary booking_new webhook, handled entirely by the existing
 * ChannexBookingIngestionService. This service only tracks the pending
 * decision and its 24-hour deadline, and answers it — automatically per the
 * hotel's configured policy, or on staff's explicit instruction.
 */
class ChannexReservationRequestService
{
    public function __construct(
        private ChannexClient $client,
        private HotelLivePublisher $livePublisher,
        private NotificationService $notifications,
    ) {}

    /**
     * @param  array<string, mixed>  $payload  the reservation_request webhook's "payload" object
     */
    public function handleIncoming(array $payload, string $channexPropertyId): ?ChannexReservationRequest
    {
        // Channex's docs confirm "bms" (booking message) uses the same shape
        // as a booking revision, but don't spell out which exact field on
        // THIS event carries the id used to resolve it (confirmed as
        // "payload.id" for the sibling alteration_request event) — checked
        // defensively since getting this wrong means we can never answer the
        // request at all.
        $liveFeedEventId = (string) ($payload['id'] ?? $payload['live_feed_event_id'] ?? '');
        if ($liveFeedEventId === '') {
            throw new RuntimeException('Channex reservation_request payload has no live_feed event id to resolve against.');
        }

        $property = ChannexProperty::where('channex_property_id', $channexPropertyId)->first();
        if ($property === null) {
            throw new RuntimeException("Unknown Channex property_id: {$channexPropertyId}");
        }

        $bms = (array) ($payload['bms'] ?? []);

        $request = ChannexReservationRequest::firstOrCreate(
            ['live_feed_event_id' => $liveFeedEventId],
            [
                'hotel_id' => $property->hotel_id,
                'channex_property_id' => $channexPropertyId,
                'message_thread_id' => $payload['message_thread_id'] ?? null,
                'channex_booking_id' => $bms['booking_id'] ?? null,
                'raw_payload' => $bms,
                'received_at' => now(),
                'status' => ChannexReservationRequest::STATUS_PENDING,
            ]
        );

        if (! $request->wasRecentlyCreated) {
            // Webhook redelivery of a request we already know about — per
            // Channex's own "out-of-order delivery" warning, never treat a
            // repeat as a brand-new request.
            return $request;
        }

        $settings = HotelAirbnbRequestSetting::forHotel((int) $property->hotel_id);

        if ($settings->mode === HotelAirbnbRequestSetting::MODE_AUTO_ACCEPT) {
            $this->resolve($request, true, null, null, ChannexReservationRequest::RESOLVED_BY_AUTO_ACCEPT);
        } elseif ($settings->mode === HotelAirbnbRequestSetting::MODE_AUTO_DECLINE) {
            $this->resolve($request, false, $settings->decline_reason, null, ChannexReservationRequest::RESOLVED_BY_AUTO_DECLINE);
        } else {
            // manual_review — leave pending; notify staff right away, then
            // again on the hotel's own reminder schedule (see the scheduled
            // command) until someone answers or the 24h fallback kicks in.
            // The persisted notification-center entry is created ONCE here,
            // not inside notifyStaff() itself — that method is reused by the
            // reminder command too, and reminders re-notify the SAME still-
            // pending request rather than being a new event to list.
            $this->notifyStaff($request->fresh());
            $this->notifications->notify(
                (int) $property->hotel_id,
                'airbnb_request',
                'NOTIFICATIONS.AIRBNB_REQUEST_TITLE',
                [],
                '/admin/airbnb-requests',
                'manage airbnb requests'
            );
        }

        return $request->fresh();
    }

    public function staffAccept(ChannexReservationRequest $request, int $userId): void
    {
        $this->resolve($request, true, null, $userId, ChannexReservationRequest::RESOLVED_BY_STAFF);
    }

    public function staffDecline(ChannexReservationRequest $request, int $userId, string $reason): void
    {
        $this->resolve($request, false, $reason, $userId, ChannexReservationRequest::RESOLVED_BY_STAFF);
    }

    /** Applied by the scheduled fallback job when manual_review times out unanswered. */
    public function applyFallback(ChannexReservationRequest $request): void
    {
        $settings = HotelAirbnbRequestSetting::forHotel((int) $request->hotel_id);
        $accept = $settings->fallback_action === HotelAirbnbRequestSetting::FALLBACK_ACCEPT;

        $this->resolve(
            $request,
            $accept,
            $settings->decline_reason,
            null,
            ChannexReservationRequest::RESOLVED_BY_FALLBACK
        );
    }

    public function notifyStaff(ChannexReservationRequest $request): void
    {
        $this->livePublisher->publish(
            [HotelLivePublisher::SCOPE_AIRBNB_REQUESTS],
            'airbnb_request_pending',
            ['type' => 'channex_reservation_request', 'id' => $request->id, 'hotel_id' => $request->hotel_id]
        );
        $request->update(['last_reminder_sent_at' => now()]);
    }

    private function resolve(
        ChannexReservationRequest $request,
        bool $accept,
        ?string $declineReason,
        ?int $byUserId,
        string $resolvedBy
    ): void {
        if (! $request->isPending()) {
            // Channex itself is final-decision-only (a second resolve call
            // returns the event unchanged) — mirror that here rather than
            // erroring on a double-click or a race with the fallback job.
            return;
        }

        $resolution = $accept
            ? ['accept' => true]
            : ['accept' => false, 'reason' => $declineReason ?: HotelAirbnbRequestSetting::DECLINE_REASONS[3]];

        try {
            $this->client->resolveLiveFeedEvent($request->live_feed_event_id, $resolution);
        } catch (\Throwable $e) {
            $request->update(['error_message' => $e->getMessage()]);
            Log::warning('Channex reservation_request resolve failed', [
                'live_feed_event_id' => $request->live_feed_event_id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }

        $request->update([
            'status' => ChannexReservationRequest::STATUS_RESOLVED,
            'resolution' => $accept ? ChannexReservationRequest::RESOLUTION_ACCEPT : ChannexReservationRequest::RESOLUTION_DECLINE,
            'resolved_by' => $resolvedBy,
            'resolved_by_user_id' => $byUserId,
            'resolved_at' => now(),
            'error_message' => null,
        ]);
    }
}
