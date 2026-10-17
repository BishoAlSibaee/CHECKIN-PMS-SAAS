<?php

namespace Tests\Feature\Channex;

use App\Events\HotelLiveUpdated;
use App\Models\ChannexProperty;
use App\Models\ChannexReservationRequest;
use App\Models\Hotel;
use App\Models\HotelAirbnbRequestSetting;
use App\Models\User;
use App\Services\Channex\ChannexReservationRequestService;
use App\Services\HotelLivePublisher;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class AirbnbReservationRequestTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['channex.enabled' => true, 'channex.api_key' => 'test-key']);
    }

    /** @return array{0: int, 1: string} [hotelId, channexPropertyId] */
    private function seedHotelAndProperty(): array
    {
        $marker = 'Airbnb Request Test '.uniqid();
        $hotel = Hotel::create(['name_ar' => $marker, 'name_en' => $marker, 'is_active' => true]);
        $channexPropertyId = 'test-airbnb-property-'.$hotel->id;

        ChannexProperty::create(['hotel_id' => $hotel->id, 'channex_property_id' => $channexPropertyId]);

        return [(int) $hotel->id, $channexPropertyId];
    }

    private function staffUser(): User
    {
        return User::create([
            'name' => 'Staff '.uniqid(),
            'job_number' => 'STAFF-'.uniqid(),
            'email' => 'staff.'.uniqid().'@example.com',
            'mobile' => '05'.substr(uniqid(), -8),
            'active' => 1,
            'password' => bcrypt('test-password'),
        ]);
    }

    /** @return array<string, mixed> */
    private function reservationRequestPayload(string $liveFeedEventId): array
    {
        return [
            'id' => $liveFeedEventId,
            'message_thread_id' => 'THREAD-'.uniqid(),
            'resolved' => false,
            'bms' => [
                'booking_id' => 'BID-'.uniqid(),
                'arrival_date' => '2027-10-01',
                'departure_date' => '2027-10-03',
            ],
        ];
    }

    public function test_auto_accept_mode_resolves_immediately_as_accepted(): void
    {
        [$hotelId, $propertyId] = $this->seedHotelAndProperty();
        HotelAirbnbRequestSetting::forHotel($hotelId)->update(['mode' => HotelAirbnbRequestSetting::MODE_AUTO_ACCEPT]);

        Http::fake(['*/live_feed/*/resolve' => Http::response(['data' => ['id' => 'x']], 200)]);

        $service = app(ChannexReservationRequestService::class);
        $liveFeedEventId = 'live-feed-'.uniqid();
        $request = $service->handleIncoming($this->reservationRequestPayload($liveFeedEventId), $propertyId);

        $this->assertSame(ChannexReservationRequest::STATUS_RESOLVED, $request->status);
        $this->assertSame(ChannexReservationRequest::RESOLUTION_ACCEPT, $request->resolution);
        $this->assertSame(ChannexReservationRequest::RESOLVED_BY_AUTO_ACCEPT, $request->resolved_by);

        Http::assertSent(fn ($req) => str_contains($req->url(), "live_feed/{$liveFeedEventId}/resolve")
            && $req['resolution'] === ['accept' => true]);
    }

    public function test_auto_decline_mode_resolves_immediately_with_configured_reason(): void
    {
        [$hotelId, $propertyId] = $this->seedHotelAndProperty();
        HotelAirbnbRequestSetting::forHotel($hotelId)->update([
            'mode' => HotelAirbnbRequestSetting::MODE_AUTO_DECLINE,
            'decline_reason' => 'dates_not_available',
        ]);

        Http::fake(['*/live_feed/*/resolve' => Http::response(['data' => ['id' => 'x']], 200)]);

        $service = app(ChannexReservationRequestService::class);
        $request = $service->handleIncoming($this->reservationRequestPayload('live-feed-'.uniqid()), $propertyId);

        $this->assertSame(ChannexReservationRequest::STATUS_RESOLVED, $request->status);
        $this->assertSame(ChannexReservationRequest::RESOLUTION_DECLINE, $request->resolution);
        $this->assertSame(ChannexReservationRequest::RESOLVED_BY_AUTO_DECLINE, $request->resolved_by);

        Http::assertSent(fn ($req) => ($req['resolution']['accept'] ?? null) === false
            && $req['resolution']['reason'] === 'dates_not_available');
    }

    public function test_manual_review_mode_leaves_pending_and_notifies_staff_live(): void
    {
        [$hotelId, $propertyId] = $this->seedHotelAndProperty();
        HotelAirbnbRequestSetting::forHotel($hotelId)->update(['mode' => HotelAirbnbRequestSetting::MODE_MANUAL_REVIEW]);
        Event::fake([HotelLiveUpdated::class]);

        $service = app(ChannexReservationRequestService::class);
        $request = $service->handleIncoming($this->reservationRequestPayload('live-feed-'.uniqid()), $propertyId);

        $this->assertTrue($request->isPending());
        $this->assertNotNull($request->fresh()->last_reminder_sent_at, 'staff must be notified immediately, not only on the next reminder tick');

        Event::assertDispatched(HotelLiveUpdated::class, fn (HotelLiveUpdated $event) => in_array(HotelLivePublisher::SCOPE_AIRBNB_REQUESTS, $event->scopes, true)
            && $event->action === 'airbnb_request_pending');

        $this->assertSame(
            1,
            \App\Models\AppNotification::where('type', 'airbnb_request')->where('hotel_id', $hotelId)->count(),
            'the header bell notification center must also get an entry for this pending request'
        );
    }

    public function test_reminder_ticks_do_not_create_duplicate_notification_center_entries(): void
    {
        [$hotelId, $propertyId] = $this->seedHotelAndProperty();
        HotelAirbnbRequestSetting::forHotel($hotelId)->update(['mode' => HotelAirbnbRequestSetting::MODE_MANUAL_REVIEW]);

        $service = app(ChannexReservationRequestService::class);
        $request = $service->handleIncoming($this->reservationRequestPayload('live-feed-'.uniqid()), $propertyId);

        $service->notifyStaff($request->fresh());
        $service->notifyStaff($request->fresh());

        $this->assertSame(
            1,
            \App\Models\AppNotification::where('type', 'airbnb_request')->where('hotel_id', $hotelId)->count(),
            'reminders must not spam new notification-center rows for the same pending request'
        );
    }

    public function test_webhook_redelivery_of_the_same_request_does_not_resolve_it_twice(): void
    {
        [$hotelId, $propertyId] = $this->seedHotelAndProperty();
        HotelAirbnbRequestSetting::forHotel($hotelId)->update(['mode' => HotelAirbnbRequestSetting::MODE_AUTO_ACCEPT]);
        Http::fake(['*/live_feed/*/resolve' => Http::response(['data' => ['id' => 'x']], 200)]);

        $service = app(ChannexReservationRequestService::class);
        $liveFeedEventId = 'live-feed-'.uniqid();
        $payload = $this->reservationRequestPayload($liveFeedEventId);

        $first = $service->handleIncoming($payload, $propertyId);
        $second = $service->handleIncoming($payload, $propertyId);

        $this->assertSame($first->id, $second->id);
        Http::assertSentCount(1);
        $this->assertSame(1, ChannexReservationRequest::where('live_feed_event_id', $liveFeedEventId)->count());
    }

    public function test_staff_accept_resolves_a_pending_manual_review_request(): void
    {
        [$hotelId, $propertyId] = $this->seedHotelAndProperty();
        HotelAirbnbRequestSetting::forHotel($hotelId)->update(['mode' => HotelAirbnbRequestSetting::MODE_MANUAL_REVIEW]);
        Http::fake(['*/live_feed/*/resolve' => Http::response(['data' => ['id' => 'x']], 200)]);

        $service = app(ChannexReservationRequestService::class);
        $request = $service->handleIncoming($this->reservationRequestPayload('live-feed-'.uniqid()), $propertyId);
        $staff = $this->staffUser();

        $service->staffAccept($request, $staff->id);

        $this->assertSame(ChannexReservationRequest::STATUS_RESOLVED, $request->fresh()->status);
        $this->assertSame(ChannexReservationRequest::RESOLUTION_ACCEPT, $request->fresh()->resolution);
        $this->assertSame(ChannexReservationRequest::RESOLVED_BY_STAFF, $request->fresh()->resolved_by);
        $this->assertSame($staff->id, $request->fresh()->resolved_by_user_id);
    }

    public function test_staff_decline_resolves_a_pending_manual_review_request_with_the_given_reason(): void
    {
        [$hotelId, $propertyId] = $this->seedHotelAndProperty();
        HotelAirbnbRequestSetting::forHotel($hotelId)->update(['mode' => HotelAirbnbRequestSetting::MODE_MANUAL_REVIEW]);
        Http::fake(['*/live_feed/*/resolve' => Http::response(['data' => ['id' => 'x']], 200)]);

        $service = app(ChannexReservationRequestService::class);
        $request = $service->handleIncoming($this->reservationRequestPayload('live-feed-'.uniqid()), $propertyId);

        $service->staffDecline($request, $this->staffUser()->id, 'not_a_good_fit');

        $this->assertSame(ChannexReservationRequest::RESOLUTION_DECLINE, $request->fresh()->resolution);
        Http::assertSent(fn ($req) => ($req['resolution']['reason'] ?? null) === 'not_a_good_fit');
    }

    public function test_apply_fallback_uses_the_hotels_configured_fallback_action(): void
    {
        [$hotelId, $propertyId] = $this->seedHotelAndProperty();
        HotelAirbnbRequestSetting::forHotel($hotelId)->update([
            'mode' => HotelAirbnbRequestSetting::MODE_MANUAL_REVIEW,
            'fallback_action' => HotelAirbnbRequestSetting::FALLBACK_ACCEPT,
        ]);
        Http::fake(['*/live_feed/*/resolve' => Http::response(['data' => ['id' => 'x']], 200)]);

        $service = app(ChannexReservationRequestService::class);
        $request = $service->handleIncoming($this->reservationRequestPayload('live-feed-'.uniqid()), $propertyId);

        $service->applyFallback($request);

        $this->assertSame(ChannexReservationRequest::RESOLUTION_ACCEPT, $request->fresh()->resolution);
        $this->assertSame(ChannexReservationRequest::RESOLVED_BY_FALLBACK, $request->fresh()->resolved_by);
    }

    public function test_resolving_an_already_resolved_request_is_a_final_decision_noop(): void
    {
        [$hotelId, $propertyId] = $this->seedHotelAndProperty();
        HotelAirbnbRequestSetting::forHotel($hotelId)->update(['mode' => HotelAirbnbRequestSetting::MODE_AUTO_ACCEPT]);
        Http::fake(['*/live_feed/*/resolve' => Http::response(['data' => ['id' => 'x']], 200)]);

        $service = app(ChannexReservationRequestService::class);
        $request = $service->handleIncoming($this->reservationRequestPayload('live-feed-'.uniqid()), $propertyId);
        $this->assertSame(ChannexReservationRequest::STATUS_RESOLVED, $request->status);

        $service->staffDecline($request, $this->staffUser()->id, 'not_comfortable');

        $this->assertSame(ChannexReservationRequest::RESOLUTION_ACCEPT, $request->fresh()->resolution, 'an already-resolved decision must not be overwritten');
        Http::assertSentCount(1);
    }

    public function test_a_failed_channex_resolve_call_is_recorded_and_rethrown(): void
    {
        [$hotelId, $propertyId] = $this->seedHotelAndProperty();
        HotelAirbnbRequestSetting::forHotel($hotelId)->update(['mode' => HotelAirbnbRequestSetting::MODE_MANUAL_REVIEW]);
        Http::fake(['*/live_feed/*/resolve' => Http::response(['errors' => ['title' => 'Not Found']], 404)]);

        $service = app(ChannexReservationRequestService::class);
        $request = $service->handleIncoming($this->reservationRequestPayload('live-feed-'.uniqid()), $propertyId);

        $this->expectException(RuntimeException::class);

        try {
            $service->staffAccept($request, $this->staffUser()->id);
        } finally {
            $this->assertTrue($request->fresh()->isPending(), 'a failed resolve must leave the request pending for retry, not silently mark it resolved');
            $this->assertNotNull($request->fresh()->error_message);
        }
    }

    public function test_unknown_channex_property_id_throws_instead_of_silently_dropping_the_request(): void
    {
        $service = app(ChannexReservationRequestService::class);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Unknown Channex property_id/');

        $service->handleIncoming($this->reservationRequestPayload('live-feed-'.uniqid()), 'no-such-property-'.uniqid());
    }
}
