<?php

namespace Tests\Feature\Channex;

use App\Models\ChannexBookingRevision;
use App\Models\ChannexProperty;
use App\Models\ChannexReservationRequest;
use App\Models\Hotel;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Regression coverage for a real gap: Channex's "non_acked_booking" event —
 * its own proactive warning that a booking revision sat unacknowledged past
 * the 30-minute non-durable window and is at risk of being permanently lost
 * — doesn't start with "booking_", so it silently fell through the
 * webhook's original "ignore anything that isn't booking_*" filter and was
 * never even logged, let alone acted on.
 */
class ChannexWebhookTest extends TestCase
{
    public function test_non_acked_booking_event_is_logged_and_not_silently_ignored(): void
    {
        Log::spy();

        $response = $this->postJson('/api/channex/webhook', [
            'event' => 'non_acked_booking',
            'payload' => [
                'booking_id' => 'BID-'.uniqid(),
                'booking_revision_id' => 'BREV-'.uniqid(),
                'property_id' => 'PROP-1',
                'channel_id' => 'CHAN-1',
                'ota_code' => 'booking_com',
                'customer_name' => 'Jane Doe',
                'arrival_date' => '2027-11-01',
                'amount' => '450.00',
                'currency' => 'SAR',
            ],
        ]);

        $response->assertOk();

        Log::shouldHaveReceived('critical')
            ->once()
            ->withArgs(fn (string $message) => str_contains($message, 'unacknowledged'));

        $this->assertDatabaseHas('channex_booking_revisions', [
            'event' => 'non_acked_booking',
        ]);
    }

    public function test_non_acked_booking_without_ids_is_logged_but_does_not_attempt_recovery(): void
    {
        Log::spy();
        $before = ChannexBookingRevision::count();

        $response = $this->postJson('/api/channex/webhook', [
            'event' => 'non_acked_booking',
            'payload' => ['ota_code' => 'expedia'],
        ]);

        $response->assertOk();
        Log::shouldHaveReceived('critical')->once();
        $this->assertSame($before, ChannexBookingRevision::count(), 'missing booking_id/booking_revision_id must not create a revision row');
    }

    public function test_unknown_event_types_are_still_ignored(): void
    {
        $before = ChannexBookingRevision::count();

        $response = $this->postJson('/api/channex/webhook', [
            'event' => 'review',
            'payload' => ['id' => 'REVIEW-1'],
        ]);

        $response->assertOk();
        $this->assertSame($before, ChannexBookingRevision::count(), 'an unhandled event type must not create a revision row');
    }

    public function test_booking_new_event_still_creates_a_revision_as_before(): void
    {
        $bookingId = 'BID-'.uniqid();
        $revisionId = 'REV-'.uniqid();

        $response = $this->postJson('/api/channex/webhook', [
            'event' => 'booking_new',
            'payload' => [
                'booking_id' => $bookingId,
                'revision_id' => $revisionId,
                'property_id' => 'PROP-1',
            ],
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('channex_booking_revisions', [
            'channex_booking_id' => $bookingId,
            'channex_revision_id' => $revisionId,
            'event' => 'booking_new',
        ]);
    }

    /**
     * reservation_request doesn't start with "booking_" either, and needs a
     * mapped ChannexProperty to resolve against — exercised here at the
     * webhook-entry level (delegation, always-200 contract); the
     * accept/decline/fallback decision logic itself is covered in
     * ChannexReservationRequestServiceTest.
     */
    public function test_reservation_request_event_creates_a_pending_request_for_manual_review(): void
    {
        $marker = 'Webhook Airbnb Test '.uniqid();
        $hotel = Hotel::create(['name_ar' => $marker, 'name_en' => $marker, 'is_active' => true]);
        $propertyId = 'test-webhook-property-'.$hotel->id;
        ChannexProperty::create(['hotel_id' => $hotel->id, 'channex_property_id' => $propertyId]);

        $liveFeedEventId = 'live-feed-'.uniqid();

        $response = $this->postJson('/api/channex/webhook', [
            'event' => 'reservation_request',
            'property_id' => $propertyId,
            'payload' => [
                'id' => $liveFeedEventId,
                'message_thread_id' => 'THREAD-1',
                'bms' => ['booking_id' => 'BID-1', 'arrival_date' => '2027-11-01', 'departure_date' => '2027-11-03'],
            ],
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('channex_reservation_requests', [
            'live_feed_event_id' => $liveFeedEventId,
            'hotel_id' => $hotel->id,
            'status' => ChannexReservationRequest::STATUS_PENDING,
        ]);
    }

    public function test_reservation_request_for_an_unmapped_property_does_not_500_the_webhook(): void
    {
        $response = $this->postJson('/api/channex/webhook', [
            'event' => 'reservation_request',
            'property_id' => 'no-such-property-'.uniqid(),
            'payload' => ['id' => 'live-feed-'.uniqid(), 'bms' => []],
        ]);

        // Never a 500 back to Channex — a retry wouldn't fix an unmapped
        // property, and this is loud enough (report()) to investigate.
        $response->assertOk();
    }

    public function test_accepted_reservation_event_is_only_logged_not_acted_on(): void
    {
        Log::spy();

        $response = $this->postJson('/api/channex/webhook', [
            'event' => 'accepted_reservation',
            'payload' => ['booking_id' => 'BID-1'],
        ]);

        $response->assertOk();
        Log::shouldHaveReceived('info')->once();
    }

    /**
     * Regression coverage for a real gap: sync_error/sync_warning/rate_error
     * are Channex's own signal that something WE pushed (rates, availability)
     * was rejected by an OTA — previously these fell through the same
     * "ignore anything not booking_*" filter that non_acked_booking used to,
     * meaning a silently failed price/availability update left the platform
     * showing stale figures with nobody ever finding out.
     */
    public function test_sync_error_event_is_logged_critically_and_notifies_staff(): void
    {
        Log::spy();
        $marker = 'Sync Error Test '.uniqid();
        $hotel = Hotel::create(['name_ar' => $marker, 'name_en' => $marker, 'is_active' => true]);
        $propertyId = 'test-sync-error-property-'.$hotel->id;
        ChannexProperty::create(['hotel_id' => $hotel->id, 'channex_property_id' => $propertyId]);

        $response = $this->postJson('/api/channex/webhook', [
            'event' => 'sync_error',
            'property_id' => $propertyId,
            'payload' => [
                'channel' => 'Agoda',
                'channel_name' => 'Agoda',
                'error_type' => 'general_error',
                'property_name' => $marker,
            ],
        ]);

        $response->assertOk();
        Log::shouldHaveReceived('critical')->once()->withArgs(fn (string $message) => str_contains($message, 'sync_error'));

        $this->assertDatabaseHas('app_notifications', [
            'hotel_id' => $hotel->id,
            'type' => 'sync_error',
            'title_key' => 'NOTIFICATIONS.CHANNEX_SYNC_ERROR_TITLE',
            'link' => '/admin/channex-connection',
            'required_permission' => 'manage ota allocation',
        ]);
    }

    /**
     * @dataProvider nonCriticalSyncEvents
     */
    public function test_non_critical_sync_events_are_logged_as_warnings_not_critical(string $event): void
    {
        Log::spy();
        $marker = 'Sync Warn Test '.uniqid();
        $hotel = Hotel::create(['name_ar' => $marker, 'name_en' => $marker, 'is_active' => true]);
        $propertyId = 'test-sync-warn-property-'.$hotel->id.'-'.$event;
        ChannexProperty::create(['hotel_id' => $hotel->id, 'channex_property_id' => $propertyId]);

        $response = $this->postJson('/api/channex/webhook', [
            'event' => $event,
            'property_id' => $propertyId,
            'payload' => ['channel_name' => 'Booking.com'],
        ]);

        $response->assertOk();
        Log::shouldHaveReceived('warning')->once();
        Log::shouldNotHaveReceived('critical');

        $this->assertDatabaseHas('app_notifications', [
            'hotel_id' => $hotel->id,
            'type' => $event,
        ]);
    }

    /** @return array<string, array{0: string}> */
    public static function nonCriticalSyncEvents(): array
    {
        return [
            'sync_warning' => ['sync_warning'],
            'rate_error' => ['rate_error'],
        ];
    }

    public function test_sync_error_for_an_unmapped_property_still_creates_a_global_notification(): void
    {
        $propertyId = 'no-such-property-'.uniqid();

        $response = $this->postJson('/api/channex/webhook', [
            'event' => 'sync_error',
            'property_id' => $propertyId,
            'payload' => ['channel_name' => 'Expedia'],
        ]);

        // Never a 500 back to Channex, and the notification still goes out
        // (hotel_id null) rather than being silently dropped just because
        // the property mapping couldn't be resolved.
        $response->assertOk();
        $this->assertDatabaseHas('app_notifications', [
            'hotel_id' => null,
            'type' => 'sync_error',
        ]);
    }

    /**
     * Regression coverage for a real gap: Channex's own docs flag
     * booking_unmapped_room/rate as "a critical mapping issue requiring
     * rapid resolution to prevent overbookings" — previously these fell
     * through the same catch-all "ignore" filter non_acked_booking used to.
     */
    public function test_booking_unmapped_room_event_is_logged_critically_and_notifies_staff(): void
    {
        Log::spy();
        $marker = 'Unmapped Room Test '.uniqid();
        $hotel = Hotel::create(['name_ar' => $marker, 'name_en' => $marker, 'is_active' => true]);
        $propertyId = 'test-unmapped-room-property-'.$hotel->id;
        ChannexProperty::create(['hotel_id' => $hotel->id, 'channex_property_id' => $propertyId]);

        $bookingId = 'BID-'.uniqid();

        $response = $this->postJson('/api/channex/webhook', [
            'event' => 'booking_unmapped_room',
            'property_id' => $propertyId,
            'payload' => [
                'booking_id' => $bookingId,
                'booking_revision_id' => 'BREV-'.uniqid(),
            ],
        ]);

        $response->assertOk();
        Log::shouldHaveReceived('critical')
            ->once()
            ->withArgs(fn (string $message) => str_contains($message, 'booking_unmapped_room'));

        $this->assertDatabaseHas('app_notifications', [
            'hotel_id' => $hotel->id,
            'type' => 'booking_unmapped_room',
            'title_key' => 'NOTIFICATIONS.CHANNEX_UNMAPPED_ROOM_TITLE',
            'link' => '/admin/channex-connection',
            'required_permission' => 'manage ota allocation',
        ]);
    }

    public function test_booking_unmapped_rate_event_is_logged_critically_and_notifies_staff(): void
    {
        Log::spy();
        $marker = 'Unmapped Rate Test '.uniqid();
        $hotel = Hotel::create(['name_ar' => $marker, 'name_en' => $marker, 'is_active' => true]);
        $propertyId = 'test-unmapped-rate-property-'.$hotel->id;
        ChannexProperty::create(['hotel_id' => $hotel->id, 'channex_property_id' => $propertyId]);

        $response = $this->postJson('/api/channex/webhook', [
            'event' => 'booking_unmapped_rate',
            'property_id' => $propertyId,
            'payload' => [
                'booking_id' => 'BID-'.uniqid(),
                'booking_revision_id' => 'BREV-'.uniqid(),
            ],
        ]);

        $response->assertOk();
        Log::shouldHaveReceived('critical')->once();

        $this->assertDatabaseHas('app_notifications', [
            'hotel_id' => $hotel->id,
            'type' => 'booking_unmapped_rate',
            'title_key' => 'NOTIFICATIONS.CHANNEX_UNMAPPED_RATE_TITLE',
        ]);
    }

    public function test_unmapped_booking_for_an_unknown_property_does_not_500_the_webhook(): void
    {
        $response = $this->postJson('/api/channex/webhook', [
            'event' => 'booking_unmapped_room',
            'property_id' => 'no-such-property-'.uniqid(),
            'payload' => ['booking_id' => 'BID-'.uniqid(), 'booking_revision_id' => 'BREV-'.uniqid()],
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('app_notifications', [
            'hotel_id' => null,
            'type' => 'booking_unmapped_room',
        ]);
    }

    /**
     * Regression coverage for a real gap: guest messages arriving through an
     * OTA's own chat system were previously invisible to this system
     * entirely — staff had to separately check each platform's dashboard.
     */
    public function test_guest_message_event_notifies_staff_with_a_preview(): void
    {
        $marker = 'Guest Message Test '.uniqid();
        $hotel = Hotel::create(['name_ar' => $marker, 'name_en' => $marker, 'is_active' => true]);
        $propertyId = 'test-message-property-'.$hotel->id;
        ChannexProperty::create(['hotel_id' => $hotel->id, 'channex_property_id' => $propertyId]);

        $response = $this->postJson('/api/channex/webhook', [
            'event' => 'message',
            'property_id' => $propertyId,
            'payload' => [
                'sender' => 'guest',
                'message' => 'What time is check-in?',
                'booking_id' => 'BID-'.uniqid(),
                'message_thread_id' => 'THREAD-'.uniqid(),
            ],
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('app_notifications', [
            'hotel_id' => $hotel->id,
            'type' => 'guest_message',
            'title_key' => 'NOTIFICATIONS.CHANNEX_GUEST_MESSAGE_TITLE',
            'link' => '/admin/channex-messages',
            'required_permission' => 'manage channex messages',
        ]);
    }

    /**
     * A "host" sender is OUR OWN outgoing reply reflected back through
     * Channex — must not notify staff about their own message.
     */
    public function test_host_originated_messages_do_not_notify_staff(): void
    {
        $marker = 'Host Message Test '.uniqid();
        $hotel = Hotel::create(['name_ar' => $marker, 'name_en' => $marker, 'is_active' => true]);
        $propertyId = 'test-host-message-property-'.$hotel->id;
        ChannexProperty::create(['hotel_id' => $hotel->id, 'channex_property_id' => $propertyId]);

        $before = \App\Models\AppNotification::where('hotel_id', $hotel->id)->count();

        $response = $this->postJson('/api/channex/webhook', [
            'event' => 'message',
            'property_id' => $propertyId,
            'payload' => ['sender' => 'host', 'message' => 'Check-in is at 3pm.'],
        ]);

        $response->assertOk();
        $this->assertSame($before, \App\Models\AppNotification::where('hotel_id', $hotel->id)->count());
    }

    public function test_a_long_guest_message_is_truncated_in_the_notification_preview(): void
    {
        $marker = 'Long Message Test '.uniqid();
        $hotel = Hotel::create(['name_ar' => $marker, 'name_en' => $marker, 'is_active' => true]);
        $propertyId = 'test-long-message-property-'.$hotel->id;
        ChannexProperty::create(['hotel_id' => $hotel->id, 'channex_property_id' => $propertyId]);

        $longMessage = str_repeat('a', 200);

        $this->postJson('/api/channex/webhook', [
            'event' => 'message',
            'property_id' => $propertyId,
            'payload' => ['sender' => 'guest', 'message' => $longMessage],
        ])->assertOk();

        $notification = \App\Models\AppNotification::where('hotel_id', $hotel->id)->where('type', 'guest_message')->firstOrFail();
        $this->assertLessThanOrEqual(81, mb_strlen($notification->params['preview']));
    }

    /**
     * Regression coverage for a real gap: guest reviews were only visible on
     * each OTA's own dashboard — never surfaced anywhere in this system.
     */
    public function test_review_event_notifies_staff_with_the_score_and_channel(): void
    {
        $marker = 'Review Test '.uniqid();
        $hotel = Hotel::create(['name_ar' => $marker, 'name_en' => $marker, 'is_active' => true]);
        $propertyId = 'test-review-property-'.$hotel->id;
        ChannexProperty::create(['hotel_id' => $hotel->id, 'channex_property_id' => $propertyId]);

        $response = $this->postJson('/api/channex/webhook', [
            'event' => 'review',
            'property_id' => $propertyId,
            'payload' => [
                'content' => 'Great stay!',
                'ota' => 'BookingCom',
                'overall_score' => 9,
                'booking_id' => 'BID-'.uniqid(),
            ],
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('app_notifications', [
            'hotel_id' => $hotel->id,
            'type' => 'review',
            'title_key' => 'NOTIFICATIONS.CHANNEX_NEW_REVIEW_TITLE',
            'link' => '/admin/channex-reviews',
            'required_permission' => 'manage channex reviews',
        ]);
    }

    public function test_updated_review_event_uses_a_distinct_title_from_a_new_review(): void
    {
        $marker = 'Updated Review Test '.uniqid();
        $hotel = Hotel::create(['name_ar' => $marker, 'name_en' => $marker, 'is_active' => true]);
        $propertyId = 'test-updated-review-property-'.$hotel->id;
        ChannexProperty::create(['hotel_id' => $hotel->id, 'channex_property_id' => $propertyId]);

        $this->postJson('/api/channex/webhook', [
            'event' => 'updated_review',
            'property_id' => $propertyId,
            'payload' => ['ota' => 'Airbnb', 'overall_score' => 7],
        ])->assertOk();

        $this->assertDatabaseHas('app_notifications', [
            'hotel_id' => $hotel->id,
            'type' => 'updated_review',
            'title_key' => 'NOTIFICATIONS.CHANNEX_REVIEW_UPDATED_TITLE',
        ]);
    }
}
