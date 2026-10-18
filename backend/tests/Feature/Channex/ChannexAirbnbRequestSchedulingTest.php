<?php

namespace Tests\Feature\Channex;

use App\Models\ChannexProperty;
use App\Models\ChannexReservationRequest;
use App\Models\Hotel;
use App\Models\HotelAirbnbRequestSetting;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Covers the two scheduled commands that make manual_review actually work
 * over time: re-notifying staff on the hotel's own cadence, and applying the
 * admin's configured fallback once a request is about to blow past Airbnb's
 * real (but Channex-unexposed) 24h response deadline.
 */
class ChannexAirbnbRequestSchedulingTest extends TestCase
{
    use \Illuminate\Foundation\Testing\DatabaseTransactions;

    protected function connectionsToTransact(): array
    {
        return ['mysql', 'mysql2'];
    }

    protected function setUp(): void
    {
        parent::setUp();

        // See ChannexReservationRequestServiceTest: Channex is disabled by
        // default in .env.testing, which would make ChannexClient throw
        // before Http::fake() below ever gets a chance to intercept.
        config(['channex.enabled' => true, 'channex.api_key' => 'test-key']);
    }

    private function seedHotel(): int
    {
        $marker = 'Airbnb Schedule Test '.uniqid();
        $hotel = Hotel::create(['name_ar' => $marker, 'name_en' => $marker, 'is_active' => true]);
        ChannexProperty::create(['hotel_id' => $hotel->id, 'channex_property_id' => 'test-sched-property-'.$hotel->id]);

        return (int) $hotel->id;
    }

    private function pendingRequestFor(int $hotelId, \DateTimeInterface $receivedAt, ?\DateTimeInterface $lastReminderSentAt = null): ChannexReservationRequest
    {
        return ChannexReservationRequest::create([
            'hotel_id' => $hotelId,
            'channex_property_id' => 'test-sched-property-'.$hotelId,
            'live_feed_event_id' => 'live-feed-'.uniqid(),
            'raw_payload' => ['booking_id' => 'test'],
            'received_at' => $receivedAt,
            'last_reminder_sent_at' => $lastReminderSentAt,
            'status' => ChannexReservationRequest::STATUS_PENDING,
        ]);
    }

    public function test_reminder_command_only_notifies_requests_past_their_own_hotels_interval(): void
    {
        $hotelId = $this->seedHotel();
        HotelAirbnbRequestSetting::forHotel($hotelId)->update([
            'mode' => HotelAirbnbRequestSetting::MODE_MANUAL_REVIEW,
            'reminder_interval_minutes' => 60,
        ]);

        $notDue = $this->pendingRequestFor($hotelId, now());
        $due = $this->pendingRequestFor($hotelId, now()->subMinutes(90));

        $this->artisan('channex:remind-pending-airbnb-requests')->assertSuccessful();

        $this->assertNull($notDue->fresh()->last_reminder_sent_at, 'a request received less than one interval ago must not be reminded yet');
        $this->assertNotNull($due->fresh()->last_reminder_sent_at, 'a request past its reminder interval must be reminded');
    }

    public function test_reminder_command_skips_requests_whose_hotel_is_no_longer_in_manual_review(): void
    {
        $hotelId = $this->seedHotel();
        HotelAirbnbRequestSetting::forHotel($hotelId)->update(['mode' => HotelAirbnbRequestSetting::MODE_AUTO_ACCEPT]);

        // Simulates a request that arrived under manual_review, then the
        // admin switched the policy before it could be answered — it should
        // no longer be nagged about, since the policy no longer asks staff
        // to decide it.
        $request = $this->pendingRequestFor($hotelId, now()->subMinutes(90));

        $this->artisan('channex:remind-pending-airbnb-requests')->assertSuccessful();

        $this->assertNull($request->fresh()->last_reminder_sent_at);
    }

    public function test_fallback_command_resolves_requests_at_their_24_hour_deadline(): void
    {
        $hotelId = $this->seedHotel();
        HotelAirbnbRequestSetting::forHotel($hotelId)->update([
            'mode' => HotelAirbnbRequestSetting::MODE_MANUAL_REVIEW,
            'fallback_action' => HotelAirbnbRequestSetting::FALLBACK_DECLINE,
            'decline_reason' => 'not_comfortable',
        ]);
        Http::fake(['*/live_feed/*/resolve' => Http::response(['data' => ['id' => 'x']], 200)]);

        $notYetDue = $this->pendingRequestFor($hotelId, now()->subHours(20));
        $expiring = $this->pendingRequestFor($hotelId, now()->subHours(24)->subMinutes(5));

        $this->artisan('channex:resolve-expiring-airbnb-requests')->assertSuccessful();

        $this->assertTrue($notYetDue->fresh()->isPending(), 'a request well within its 24h window must not be auto-resolved yet');
        $this->assertSame(ChannexReservationRequest::STATUS_RESOLVED, $expiring->fresh()->status);
        $this->assertSame(ChannexReservationRequest::RESOLUTION_DECLINE, $expiring->fresh()->resolution);
        $this->assertSame(ChannexReservationRequest::RESOLVED_BY_FALLBACK, $expiring->fresh()->resolved_by);
    }

    public function test_fallback_command_leaves_a_request_pending_if_the_channex_resolve_call_fails(): void
    {
        $hotelId = $this->seedHotel();
        HotelAirbnbRequestSetting::forHotel($hotelId)->update(['mode' => HotelAirbnbRequestSetting::MODE_MANUAL_REVIEW]);
        Http::fake(['*/live_feed/*/resolve' => Http::response(['errors' => ['title' => 'Not Found']], 404)]);

        $expiring = $this->pendingRequestFor($hotelId, now()->subHours(24)->subMinutes(5));

        // Must not blow up the whole scheduled run over one hotel's failure.
        $this->artisan('channex:resolve-expiring-airbnb-requests')->assertSuccessful();

        $this->assertTrue($expiring->fresh()->isPending(), 'a failed resolve must not be lost — it stays pending for the next run');
    }
}
