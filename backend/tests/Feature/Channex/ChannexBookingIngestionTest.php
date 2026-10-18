<?php

namespace Tests\Feature\Channex;

use App\Models\ChannexProperty;
use App\Models\ChannexRatePlanMapping;
use App\Models\ChannexRoomTypeMapping;
use App\Models\OtaRatePlan;
use App\Models\RoomType;
use App\Models\RoomTypeOtaAllocation;
use App\Models\ChannexBookingRevision;
use App\Services\Channex\ChannexBookingIngestionService;
use App\Services\PricingEngine;
use Database\Seeders\PeakDayAndMonthSeeder;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/**
 * Regression coverage for a real bug: booking ingestion assigned a physical
 * room purely on date-overlap, without ever checking the manual OTA
 * allocation ceiling (RoomTypeOtaAllocation) that Phase 1/4 enforce for
 * everything else — a hotel with allocation 0 could still have Channex
 * bookings silently consume real rooms.
 */
class ChannexBookingIngestionTest extends TestCase
{
    use \Illuminate\Foundation\Testing\DatabaseTransactions;

    protected function connectionsToTransact(): array
    {
        return ['mysql', 'mysql2'];
    }

    /**
     * A fully self-contained hotel/building/room-type/room, never touched by
     * anything else in this shared, never-reset test database. Earlier
     * revisions of this fixture reused RoomType::query()->first() and
     * DB::table('buildings')->value('id') (via the shared
     * createIsolatedTestRoom() helper) — deterministic on their own, but
     * ambiguous once many other unrelated tests (Reservation, Finance) have
     * also created hotels/buildings/room types, which made this test's
     * OTA-allocation math collide with unrelated reservations when the full
     * suite ran together. A dedicated, uniquely-named room type sidesteps
     * that "which row is first" ambiguity entirely.
     *
     * @return array{0: int, 1: RoomType, 2: ChannexRoomTypeMapping}
     */
    private function seedChannexFixtures(): array
    {
        // Only seed when genuinely missing — PeakDayAndMonthSeeder's
        // updateOrCreate() resets every month/day's "check" flag back to 0
        // unconditionally, which would wipe out peak configuration that
        // OTHER already-run tests in the same suite depend on staying stable
        // for their own already-created reservations' stored pricing to
        // still match a fresh PricingEngine recomputation later in the run.
        if (\App\Models\PeakMonth::count() === 0 || \App\Models\PeakDay::count() === 0) {
            $this->artisan('db:seed', [
                '--class' => PeakDayAndMonthSeeder::class,
                '--force' => true,
                '--no-interaction' => true,
            ]);
        }

        // The OTA-prepaid-recognition journal entry (posted for
        // payment_collect=channel bookings) needs account 1100 to exist
        // alongside 1150 (the latter is seeded by a migration directly, so a
        // "count === 0" guard would never trigger here) — normally seeded by
        // ReservationTestDataSeeder alongside everything else, which this
        // fixture deliberately avoids (see docblock above).
        // ChartOfAccountsSeeder is updateOrCreate-based, so — like every
        // other test in this codebase that calls it — it's safe to run
        // unconditionally against a shared, never-reset DB.
        $this->artisan('db:seed', [
            '--class' => \Database\Seeders\ChartOfAccountsSeeder::class,
            '--force' => true,
            '--no-interaction' => true,
        ]);

        // ChannexBookingIngestionService looks this up globally
        // (Stay_reason::query()->value('id')) with no hotel scoping, so it
        // must exist somewhere — normally seeded by ReservationTestDataSeeder,
        // which this fixture deliberately avoids (see docblock above).
        \App\Models\Stay_reason::firstOrCreate(
            ['name_en' => 'Tourism'],
            ['name_ar' => 'سياحة', 'description' => 'Test stay reason']
        );

        $marker = 'Channex Test '.uniqid();

        $hotel = \App\Models\Hotel::create(['name_ar' => $marker, 'name_en' => $marker, 'is_active' => true]);
        $building = \App\Models\Building::create(['hotel_id' => $hotel->id, 'number' => 'B1', 'name' => $marker]);
        $floor = \App\Models\Floor::create(['building_id' => $building->id, 'number' => '1', 'active' => 1]);
        $roomType = RoomType::create([
            'name_ar' => $marker,
            'name_en' => $marker,
            'description' => 'Channex test fixture',
            'Min_daily_price' => 180,
            'Max_daily_price' => 280,
            'Min_monthly_price' => 4200,
            'Max_monthly_price' => 6500,
            'active_type' => 1,
        ]);
        \App\Models\Room::unguarded(function () use ($building, $floor, $roomType) {
            \App\Models\Room::create([
                'number' => 'CX'.substr((string) microtime(true), -6).random_int(10, 99),
                'building_id' => $building->id,
                'floor_id' => $floor->id,
                'suite_id' => null,
                'room_type_id' => $roomType->id,
                'capacity' => 2,
                'active' => 1,
                'roomStatus' => \App\Services\ReservationRoomStatusService::ROOM_AVAILABLE,
            ]);
        });

        $hotelId = (int) $hotel->id;

        ChannexProperty::updateOrCreate(
            ['hotel_id' => $hotelId],
            ['channex_property_id' => 'test-property-'.$hotelId]
        );

        $mapping = ChannexRoomTypeMapping::updateOrCreate(
            ['hotel_id' => $hotelId, 'room_type_id' => $roomType->id],
            ['channex_room_type_id' => 'test-channex-room-type-'.$roomType->id]
        );

        return [$hotelId, $roomType, $mapping];
    }

    /** @return array<string, mixed> */
    private function bookingPayload(
        string $channexPropertyId,
        string $channexRoomTypeId,
        string $checkin,
        string $checkout,
        string $bookingId,
        string $email,
        ?string $channexRatePlanId = null
    ): array {
        return [
            'booking_id' => $bookingId,
            'revision_id' => 'REV-'.$bookingId,
            'property_id' => $channexPropertyId,
            'arrival_date' => $checkin,
            'departure_date' => $checkout,
            'amount' => 300.0,
            'ota_commission' => null,
            'payment_collect' => 'property',
            'ota_name' => 'Test OTA',
            'channel_id' => 'test_channel',
            'customer' => [
                'name' => 'Guest',
                'surname' => $bookingId,
                'mail' => $email,
                'phone' => '0500000000',
                'country' => 'SA',
            ],
            'rooms' => [[
                'room_type_id' => $channexRoomTypeId,
                'rate_plan_id' => $channexRatePlanId,
                'checkin_date' => $checkin,
                'checkout_date' => $checkout,
                'amount' => 300.0,
            ]],
        ];
    }

    public function test_booking_is_rejected_when_ota_allocation_is_zero(): void
    {
        [$hotelId, $roomType, $mapping] = $this->seedChannexFixtures();
        $property = ChannexProperty::where('hotel_id', $hotelId)->firstOrFail();

        RoomTypeOtaAllocation::updateOrCreate(
            ['hotel_id' => $hotelId, 'room_type_id' => $roomType->id],
            ['allocated_rooms' => 0]
        );

        $service = app(ChannexBookingIngestionService::class);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/no OTA allocation headroom/');

        $service->ingest($this->bookingPayload(
            $property->channex_property_id,
            $mapping->channex_room_type_id,
            '2027-05-10',
            '2027-05-12',
            'TEST-ZERO-ALLOC-'.uniqid(),
            'zero.alloc.'.uniqid().'@example.com'
        ));
    }

    public function test_booking_succeeds_when_allocation_covers_it(): void
    {
        [$hotelId, $roomType, $mapping] = $this->seedChannexFixtures();
        $property = ChannexProperty::where('hotel_id', $hotelId)->firstOrFail();

        RoomTypeOtaAllocation::updateOrCreate(
            ['hotel_id' => $hotelId, 'room_type_id' => $roomType->id],
            ['allocated_rooms' => 1]
        );

        $service = app(ChannexBookingIngestionService::class);
        $bookingId = 'TEST-ONE-ALLOC-'.uniqid();

        $reservation = $service->ingest($this->bookingPayload(
            $property->channex_property_id,
            $mapping->channex_room_type_id,
            '2027-06-10',
            '2027-06-12',
            $bookingId,
            'one.alloc.'.uniqid().'@example.com'
        ));

        $this->assertNotNull($reservation->id);
        $this->assertSame('pay_at_hotel', $reservation->ota_payment_type);
        $this->assertSame($bookingId, $reservation->external_booking_id);
        $this->assertSame(\App\Models\Reservation::STATUS_CONFIRMED, $reservation->reservation_status);
    }

    public function test_second_overlapping_booking_rejected_once_allocation_is_exhausted(): void
    {
        [$hotelId, $roomType, $mapping] = $this->seedChannexFixtures();
        $property = ChannexProperty::where('hotel_id', $hotelId)->firstOrFail();

        // Exactly one room of this type released to OTA channels.
        RoomTypeOtaAllocation::updateOrCreate(
            ['hotel_id' => $hotelId, 'room_type_id' => $roomType->id],
            ['allocated_rooms' => 1]
        );

        $service = app(ChannexBookingIngestionService::class);

        $first = $service->ingest($this->bookingPayload(
            $property->channex_property_id,
            $mapping->channex_room_type_id,
            '2027-07-10',
            '2027-07-13',
            'TEST-FIRST-'.uniqid(),
            'first.'.uniqid().'@example.com'
        ));
        $this->assertNotNull($first->id);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/no OTA allocation headroom/');

        // Same room type, overlapping dates, same 1-room ceiling already used up.
        $service->ingest($this->bookingPayload(
            $property->channex_property_id,
            $mapping->channex_room_type_id,
            '2027-07-11',
            '2027-07-14',
            'TEST-SECOND-'.uniqid(),
            'second.'.uniqid().'@example.com'
        ));
    }

    /**
     * Regression coverage for a second real bug: the reservation created from
     * an incoming booking was priced by the hotel's own default calculation,
     * completely ignoring which OTA rate plan the guest actually booked (and
     * was quoted/charged) on the platform — so a "-10% non-refundable" plan's
     * guest could be folio'd at the full, un-discounted rate internally.
     */
    public function test_reservation_is_priced_using_the_booked_rate_plans_adjustment(): void
    {
        [$hotelId, $roomType, $mapping] = $this->seedChannexFixtures();
        $property = ChannexProperty::where('hotel_id', $hotelId)->firstOrFail();

        RoomTypeOtaAllocation::updateOrCreate(
            ['hotel_id' => $hotelId, 'room_type_id' => $roomType->id],
            ['allocated_rooms' => 1]
        );

        $ratePlan = OtaRatePlan::create([
            'hotel_id' => $hotelId,
            'room_type_id' => $roomType->id,
            'name_ar' => 'خطة غير قابلة للاسترداد',
            'name_en' => 'Non-refundable -10%',
            'price_adjustment_percent' => -10,
            'is_refundable' => false,
            'is_active' => true,
        ]);
        $channexRatePlanId = 'test-channex-rate-plan-'.uniqid();
        ChannexRatePlanMapping::create([
            'ota_rate_plan_id' => $ratePlan->id,
            'channex_rate_plan_id' => $channexRatePlanId,
        ]);

        $room = $roomType->rooms()->where('active', 1)->firstOrFail();
        $engine = app(PricingEngine::class);
        $naturalPrice = $engine->calculateStayBase($room, '2027-08-10', '2027-08-13', 0, 0);
        $expectedTotal = round(round($naturalPrice * 0.9, 2) * 1.15, 2);

        $service = app(ChannexBookingIngestionService::class);
        $reservation = $service->ingest($this->bookingPayload(
            $property->channex_property_id,
            $mapping->channex_room_type_id,
            '2027-08-10',
            '2027-08-13',
            'TEST-RATE-PLAN-'.uniqid(),
            'rateplan.'.uniqid().'@example.com',
            $channexRatePlanId
        ));

        $this->assertEqualsWithDelta(round($naturalPrice * 0.9, 2), (float) $reservation->base_price, 0.02);
        $this->assertEqualsWithDelta($expectedTotal, (float) $reservation->total, 0.02);
        $this->assertLessThan($naturalPrice, (float) $reservation->base_price);
    }

    /**
     * Regression coverage for a third real bug: every webhook event
     * (booking_new / booking_modification / booking_cancellation) was routed
     * through the exact same "create a reservation" path with no check for
     * an already-ingested booking_id — a guest changing their dates on the
     * platform made ingestion try to book a SECOND room for the same
     * booking_id, then crash on the [reservation_source_id, external_booking_id]
     * unique constraint, leaving a duplicate reservation that had consumed a
     * real room and OTA allocation slot but was never linked to anything.
     */
    public function test_modification_cancels_the_superseded_reservation_and_rebooks(): void
    {
        [$hotelId, $roomType, $mapping] = $this->seedChannexFixtures();
        $property = ChannexProperty::where('hotel_id', $hotelId)->firstOrFail();

        RoomTypeOtaAllocation::updateOrCreate(
            ['hotel_id' => $hotelId, 'room_type_id' => $roomType->id],
            ['allocated_rooms' => 1]
        );

        $service = app(ChannexBookingIngestionService::class);
        $bookingId = 'TEST-MODIFY-'.uniqid();

        $original = $service->ingest($this->bookingPayload(
            $property->channex_property_id,
            $mapping->channex_room_type_id,
            '2027-09-10',
            '2027-09-12',
            $bookingId,
            'modify.'.uniqid().'@example.com'
        ), 'booking_new');

        $modified = $service->ingest($this->bookingPayload(
            $property->channex_property_id,
            $mapping->channex_room_type_id,
            '2027-09-11',
            '2027-09-13',
            $bookingId,
            'modify.'.uniqid().'@example.com'
        ), 'booking_modification');

        $this->assertNotSame($original->id, $modified->id);
        $this->assertSame('2027-09-11', $modified->start_date);
        $this->assertSame(\App\Models\Reservation::STATUS_CONFIRMED, $modified->reservation_status);

        $original->refresh();
        $this->assertSame(\App\Models\Reservation::STATUS_CANCELLED, $original->reservation_status);
        $this->assertNotSame($bookingId, $original->external_booking_id, 'superseded reservation must free the booking_id slot');

        // The night the superseded reservation alone covered (Sep 10-11,
        // before the rebooked stay even starts on Sep 11) must not still be
        // blocked — checking the full original range would give a false
        // positive, since the rebooked reservation legitimately overlaps it
        // from Sep 11 onward.
        $originalRoomId = $original->reservationRooms->first()->room_id;
        $stillBlocked = \App\Models\ReservationRoom::where('room_id', $originalRoomId)
            ->whereHas('reservation', fn ($q) => $q
                ->whereNotIn('reservation_status', \App\Models\Reservation::nonBlockingInventoryStatuses())
                ->where('start_date', '<', '2027-09-11')
                ->where('expire_date', '>', '2027-09-10'))
            ->exists();
        $this->assertFalse($stillBlocked, 'the superseded reservation must no longer block the night only it covered');
    }

    /**
     * Regression coverage for the header-bell notification center: a
     * genuinely new booking must produce a "new booking" entry, but a
     * modification (which internally cancels-and-rebooks the very same
     * booking_id) must NOT be reported as a second new booking.
     */
    public function test_a_new_booking_notifies_but_a_modification_of_it_does_not(): void
    {
        [$hotelId, $roomType, $mapping] = $this->seedChannexFixtures();
        $property = ChannexProperty::where('hotel_id', $hotelId)->firstOrFail();

        RoomTypeOtaAllocation::updateOrCreate(
            ['hotel_id' => $hotelId, 'room_type_id' => $roomType->id],
            ['allocated_rooms' => 1]
        );

        $service = app(ChannexBookingIngestionService::class);
        $bookingId = 'TEST-NOTIFY-'.uniqid();

        $service->ingest($this->bookingPayload(
            $property->channex_property_id,
            $mapping->channex_room_type_id,
            '2027-10-10',
            '2027-10-12',
            $bookingId,
            'notify.'.uniqid().'@example.com'
        ), 'booking_new');

        $this->assertSame(
            1,
            \App\Models\AppNotification::where('type', 'new_booking')->where('hotel_id', $hotelId)->count(),
            'a genuinely new OTA booking must appear in the notification center'
        );

        $service->ingest($this->bookingPayload(
            $property->channex_property_id,
            $mapping->channex_room_type_id,
            '2027-10-11',
            '2027-10-13',
            $bookingId,
            'notify.'.uniqid().'@example.com'
        ), 'booking_modification');

        $this->assertSame(
            1,
            \App\Models\AppNotification::where('type', 'new_booking')->where('hotel_id', $hotelId)->count(),
            'a modification of an already-ingested booking must not be reported as a second new booking'
        );
    }

    public function test_cancellation_releases_the_room_and_reverses_the_prepaid_entry(): void
    {
        [$hotelId, $roomType, $mapping] = $this->seedChannexFixtures();
        $property = ChannexProperty::where('hotel_id', $hotelId)->firstOrFail();

        RoomTypeOtaAllocation::updateOrCreate(
            ['hotel_id' => $hotelId, 'room_type_id' => $roomType->id],
            ['allocated_rooms' => 1]
        );

        $service = app(ChannexBookingIngestionService::class);
        $bookingId = 'TEST-CANCEL-'.uniqid();

        $payload = $this->bookingPayload(
            $property->channex_property_id,
            $mapping->channex_room_type_id,
            '2027-09-20',
            '2027-09-22',
            $bookingId,
            'cancel.'.uniqid().'@example.com'
        );
        $payload['payment_collect'] = 'channel'; // prepaid_platform, so a real journal entry gets posted
        $payload['amount'] = 500.0;

        $reservation = $service->ingest($payload, 'booking_new');
        $this->assertTrue(
            \App\Models\JournalEntry::where('reference', 'OTA-PREPAID-'.$reservation->id)->exists(),
            'sanity check: the prepaid recognition entry must exist before testing its reversal'
        );

        $result = $service->ingest($payload, 'booking_cancellation');

        $this->assertSame($reservation->id, $result->id);
        $this->assertSame(\App\Models\Reservation::STATUS_CANCELLED, $result->reservation_status);
        $this->assertTrue(
            \App\Models\JournalEntry::where('reference', 'OTA-PREPAID-REVERSAL-'.$reservation->id)->exists(),
            'the prepaid recognition entry must be reversed once Channex cancels the booking'
        );

        $roomId = $result->reservationRooms->first()->room_id;
        $stillBlocked = \App\Models\ReservationRoom::where('room_id', $roomId)
            ->whereHas('reservation', fn ($q) => $q
                ->whereNotIn('reservation_status', \App\Models\Reservation::nonBlockingInventoryStatuses())
                ->where('start_date', '<', '2027-09-22')
                ->where('expire_date', '>', '2027-09-20'))
            ->exists();
        $this->assertFalse($stillBlocked, 'a cancelled OTA booking must release its room immediately');
    }

    /**
     * Regression coverage for a real gap: the webhook path gets an explicit
     * event name (booking_new/booking_modification/booking_cancellation),
     * but the polling/backfill safety-net paths have no such hint — they
     * used to leave ChannexBookingRevision.event null and lean entirely on
     * ingest()'s own secondary status-field fallback. Channex's docs confirm
     * the "status" field (new/modified/cancelled) is present on ALL of the
     * booking_revisions feed item, the full detail, and the plain Booking
     * object — this locks in the translation to the webhook's own event
     * vocabulary.
     */
    public function test_map_booking_status_to_event_translates_every_known_status(): void
    {
        $this->assertSame('booking_new', ChannexBookingIngestionService::mapBookingStatusToEvent('new'));
        $this->assertSame('booking_modification', ChannexBookingIngestionService::mapBookingStatusToEvent('modified'));
        $this->assertSame('booking_cancellation', ChannexBookingIngestionService::mapBookingStatusToEvent('cancelled'));
        $this->assertSame('booking_cancellation', ChannexBookingIngestionService::mapBookingStatusToEvent('canceled'));
        $this->assertSame('booking_cancellation', ChannexBookingIngestionService::mapBookingStatusToEvent('CANCELLED'));
        $this->assertNull(ChannexBookingIngestionService::mapBookingStatusToEvent('something-unrecognized'));
        $this->assertNull(ChannexBookingIngestionService::mapBookingStatusToEvent(null));
    }

    public function test_poll_command_reliably_detects_a_cancellation_via_the_feeds_status_field(): void
    {
        // .env.testing deliberately leaves Channex disabled; scoped to this
        // test (not setUp()) so the other tests in this file keep their
        // existing behavior of Channex genuinely being off.
        config(['channex.enabled' => true, 'channex.api_key' => 'test-key']);

        [$hotelId, $roomType, $mapping] = $this->seedChannexFixtures();
        $property = ChannexProperty::where('hotel_id', $hotelId)->firstOrFail();

        RoomTypeOtaAllocation::updateOrCreate(
            ['hotel_id' => $hotelId, 'room_type_id' => $roomType->id],
            ['allocated_rooms' => 1]
        );

        $bookingId = 'TEST-POLL-CANCEL-'.uniqid();
        $payload = $this->bookingPayload(
            $property->channex_property_id,
            $mapping->channex_room_type_id,
            '2027-11-10',
            '2027-11-12',
            $bookingId,
            'pollcancel.'.uniqid().'@example.com'
        );

        $revisionId = 'POLL-REV-'.uniqid();
        $cancelledDetail = array_merge($payload, ['status' => 'cancelled']);

        // Http::fake() must be active BEFORE the setup ingest() below too —
        // with Channex enabled, creating/cancelling a reservation
        // synchronously fires PushRoomTypeAvailabilityJob (QUEUE_CONNECTION
        // is sync), which would otherwise hit the real Channex API. The
        // catch-all keeps every such call harmless without having to
        // individually fake availability/restrictions endpoints.
        Http::fake([
            '*/booking_revisions/feed' => Http::response(['data' => [
                [
                    'type' => 'booking_revision',
                    'id' => $revisionId,
                    'attributes' => [
                        'booking_id' => $bookingId,
                        'revision_id' => $revisionId,
                        'property_id' => $property->channex_property_id,
                        'status' => 'cancelled',
                    ],
                ],
            ]], 200),
            '*/booking_revisions/*/ack' => Http::response(['meta' => ['message' => 'Success']], 200),
            '*/booking_revisions/*' => Http::response([
                'data' => ['type' => 'booking_revision', 'id' => $revisionId, 'attributes' => $cancelledDetail],
            ], 200),
            '*' => Http::response(['data' => []], 200),
        ]);

        // Ingested directly first (as the original webhook delivery would
        // have done) — the poll below must discover ITS cancellation.
        $reservation = app(ChannexBookingIngestionService::class)->ingest($payload, 'booking_new');
        $this->assertSame(\App\Models\Reservation::STATUS_CONFIRMED, $reservation->reservation_status);

        $this->artisan('channex:poll-booking-revisions')->assertSuccessful();

        $revision = ChannexBookingRevision::where('channex_booking_id', $bookingId)->firstOrFail();
        $this->assertSame(
            'booking_cancellation',
            $revision->event,
            'the poller must capture the feed status as an event hint, not leave it null'
        );
        $this->assertSame(ChannexBookingRevision::STATUS_PROCESSED, $revision->status);

        $reservation->refresh();
        $this->assertSame(
            \App\Models\Reservation::STATUS_CANCELLED,
            $reservation->reservation_status,
            'a poll-discovered cancellation must actually cancel the reservation, not be silently misread as a new/duplicate booking'
        );
    }

    public function test_backfill_command_reliably_detects_a_cancellation_via_the_bookings_status_field(): void
    {
        config(['channex.enabled' => true, 'channex.api_key' => 'test-key']);

        [$hotelId, $roomType, $mapping] = $this->seedChannexFixtures();
        $property = ChannexProperty::where('hotel_id', $hotelId)->firstOrFail();

        RoomTypeOtaAllocation::updateOrCreate(
            ['hotel_id' => $hotelId, 'room_type_id' => $roomType->id],
            ['allocated_rooms' => 1]
        );

        $bookingId = 'TEST-BACKFILL-CANCEL-'.uniqid();
        $payload = $this->bookingPayload(
            $property->channex_property_id,
            $mapping->channex_room_type_id,
            '2027-11-15',
            '2027-11-17',
            $bookingId,
            'backfillcancel.'.uniqid().'@example.com'
        );

        $revisionId = 'BACKFILL-REV-'.uniqid();
        $cancelledDetail = array_merge($payload, ['status' => 'cancelled']);

        Http::fake([
            '*/bookings?*' => Http::response(['data' => [
                [
                    'type' => 'booking',
                    'id' => $revisionId,
                    'attributes' => array_merge($cancelledDetail, ['revision_id' => $revisionId]),
                ],
            ]], 200),
            '*/booking_revisions/*/ack' => Http::response(['meta' => ['message' => 'Success']], 200),
            '*/booking_revisions/*' => Http::response([
                'data' => ['type' => 'booking_revision', 'id' => $revisionId, 'attributes' => $cancelledDetail],
            ], 200),
            '*' => Http::response(['data' => []], 200),
        ]);

        $reservation = app(ChannexBookingIngestionService::class)->ingest($payload, 'booking_new');

        $this->artisan('channex:backfill-bookings')->assertSuccessful();

        $revision = ChannexBookingRevision::where('channex_booking_id', $bookingId)->firstOrFail();
        $this->assertSame('booking_cancellation', $revision->event);

        $reservation->refresh();
        $this->assertSame(\App\Models\Reservation::STATUS_CANCELLED, $reservation->reservation_status);
    }

    public function test_cancellation_for_an_unknown_booking_id_is_a_safe_noop(): void
    {
        [$hotelId, $roomType, $mapping] = $this->seedChannexFixtures();
        $property = ChannexProperty::where('hotel_id', $hotelId)->firstOrFail();

        $service = app(ChannexBookingIngestionService::class);

        $result = $service->ingest($this->bookingPayload(
            $property->channex_property_id,
            $mapping->channex_room_type_id,
            '2027-09-25',
            '2027-09-27',
            'TEST-NEVER-SEEN-'.uniqid(),
            'neverseen.'.uniqid().'@example.com'
        ), 'booking_cancellation');

        $this->assertNull($result);
    }
}
