<?php

namespace Tests\Feature\Grms;

use App\Models\Client;
use App\Models\GrmsOutboxEvent;
use App\Models\Reservation;
use App\Models\Reservation_source;
use App\Models\ReservationRoom;
use App\Models\Room;
use App\Models\Stay_reason;
use App\Models\Suite;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * GRMS v1 contract section 5 "Suites" — a whole-suite booking carries an
 * optional `suite` marker alongside its member rooms; a booking that merely
 * touches some of a suite's rooms (not all of them, or mixed with an
 * ordinary room) must not. Also covers the suite_partial_cancel guard and
 * the room.moved -> debounced reservation.updated switch for suite moves.
 */
class GrmsSuiteTest extends TestCase
{
    use \Illuminate\Foundation\Testing\DatabaseTransactions;

    protected function connectionsToTransact(): array
    {
        return ['mysql', 'mysql2'];
    }

    /** @return array{0: Suite, 1: Room, 2: Room, 3: Room} [suite, memberA, memberB, ordinaryRoom] */
    private function makeSuiteFixture(): array
    {
        $this->artisan('db:seed', [
            '--class' => \Database\Seeders\EssentialReferenceDataSeeder::class,
            '--force' => true,
            '--no-interaction' => true,
        ]);

        $hotelId = (int) DB::table('hotels')->orderBy('id')->value('id');
        $buildingId = DB::table('buildings')->where('hotel_id', $hotelId)->value('id');
        $floorId = DB::table('floors')->where('building_id', $buildingId)->value('id');
        $roomTypeId = DB::table('room_types')->value('id');
        $suffix = $this->uniqueSuffix();

        $suite = Suite::create([
            'building_id' => $buildingId,
            'floor_id' => $floorId,
            'number' => 'ST'.$suffix,
            'suiteStatus' => 0,
            'active' => 1,
        ]);

        $memberA = $this->createRoom($buildingId, $floorId, $roomTypeId, 'SA'.$suffix, $suite->id);
        $memberB = $this->createRoom($buildingId, $floorId, $roomTypeId, 'SB'.$suffix, $suite->id);
        $ordinaryRoom = $this->createRoom($buildingId, $floorId, $roomTypeId, 'SO'.$suffix, null);

        return [$suite, $memberA, $memberB, $ordinaryRoom];
    }

    private function createRoom(int $buildingId, int $floorId, int $roomTypeId, string $number, ?int $suiteId): Room
    {
        $id = DB::table('rooms')->insertGetId([
            'building_id' => $buildingId, 'floor_id' => $floorId, 'suite_id' => $suiteId,
            'number' => $number, 'room_type_id' => $roomTypeId, 'capacity' => 2,
            'roomStatus' => 1, 'active' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return Room::findOrFail($id);
    }

    private function makeReservationWithRooms(array $rooms, string $start, string $end): array
    {
        $client = Client::first() ?? Client::create([
            'first_name' => 'Guest', 'last_name' => 'Suite'.$this->uniqueSuffix(),
            'international_code' => '+966', 'mobile' => '05'.substr($this->uniqueSuffix(), -8),
            'IdType' => 'PASSPORT', 'IdNumber' => 'TEST-'.$this->uniqueSuffix(),
            'gender' => 'MALE', 'guest_type' => 'VISITOR',
        ]);
        $stayReason = Stay_reason::first();
        $source = Reservation_source::first();

        $reservation = Reservation::query()->create([
            'client_id' => $client->id,
            'start_date' => $start,
            'expire_date' => $end,
            'nights' => Carbon::parse($start)->diffInDays(Carbon::parse($end)),
            'reservation_type' => 0,
            'reservation_status' => Reservation::STATUS_CONFIRMED,
            'stay_reason_id' => $stayReason->id,
            'reservation_source_id' => $source->id,
            'rent_type' => 0,
            'price_calculation_mode' => 0,
            'base_price' => 500,
            'classification_discount' => 0,
            'discount' => 0,
            'extras' => 0,
            'penalties' => 0,
            'subtotal' => 500,
            'taxes' => 75,
            'total' => 575,
            'logedin' => Reservation::LOGEDIN_NOT_IN_HOUSE,
            'login_time' => $start,
            'user_id' => $this->apiTestUser()->id,
        ]);

        $resRooms = [];
        foreach ($rooms as $room) {
            $resRooms[] = ReservationRoom::query()->create([
                'reservation_id' => $reservation->id,
                'room_id' => $room->id,
                'price' => 287.5,
            ]);
        }

        return [$reservation, $resRooms];
    }

    public function test_a_whole_suite_booking_carries_the_suite_marker(): void
    {
        Carbon::setTestNow('2026-08-16');
        [$suite, $memberA, $memberB] = $this->makeSuiteFixture();
        $hotelId = (int) $memberA->building->hotel_id;
        DB::table('hotels')->where('id', $hotelId)->update(['pms_protocol' => 'v1']);

        [$reservation] = $this->makeReservationWithRooms([$memberA, $memberB], '2026-08-16', '2026-08-18');

        $user = $this->userWithApiPermissions(['view reservations', 'update reservations']);
        $response = $this->actingAs($user, 'api')->patchJson(
            "/api/users/reservations/{$reservation->id}",
            ['extras' => 1], // no-op-ish update, just to trigger notifyReservationLive via a real action
        );
        $response->assertOk();

        $event = GrmsOutboxEvent::where('entity_id', (string) $reservation->id)->latest('id')->first();
        $this->assertNotNull($event);
        $this->assertSame((string) $suite->id, $event->payload['data']['reservation']['suite']['id']);
        $this->assertSame($suite->number, $event->payload['data']['reservation']['suite']['number']);
        $this->assertCount(2, $event->payload['data']['reservation']['rooms']);

        Carbon::setTestNow();
    }

    public function test_a_partial_suite_booking_has_no_suite_marker(): void
    {
        Carbon::setTestNow('2026-08-16');
        [, $memberA] = $this->makeSuiteFixture();
        $hotelId = (int) $memberA->building->hotel_id;
        DB::table('hotels')->where('id', $hotelId)->update(['pms_protocol' => 'v1']);

        // Only ONE of the suite's two member rooms — not a whole-suite booking.
        [$reservation] = $this->makeReservationWithRooms([$memberA], '2026-08-16', '2026-08-18');

        $user = $this->userWithApiPermissions(['view reservations', 'update reservations']);
        $response = $this->actingAs($user, 'api')->patchJson(
            "/api/users/reservations/{$reservation->id}",
            ['extras' => 1],
        );
        $response->assertOk();

        $event = GrmsOutboxEvent::where('entity_id', (string) $reservation->id)->latest('id')->first();
        $this->assertNotNull($event);
        $this->assertArrayNotHasKey('suite', $event->payload['data']['reservation']);

        Carbon::setTestNow();
    }

    public function test_mixing_a_suite_with_an_ordinary_room_has_no_suite_marker(): void
    {
        Carbon::setTestNow('2026-08-16');
        [, $memberA, $memberB, $ordinaryRoom] = $this->makeSuiteFixture();
        $hotelId = (int) $memberA->building->hotel_id;
        DB::table('hotels')->where('id', $hotelId)->update(['pms_protocol' => 'v1']);

        [$reservation] = $this->makeReservationWithRooms([$memberA, $memberB, $ordinaryRoom], '2026-08-16', '2026-08-18');

        $user = $this->userWithApiPermissions(['view reservations', 'update reservations']);
        $response = $this->actingAs($user, 'api')->patchJson(
            "/api/users/reservations/{$reservation->id}",
            ['extras' => 1],
        );
        $response->assertOk();

        $event = GrmsOutboxEvent::where('entity_id', (string) $reservation->id)->latest('id')->first();
        $this->assertNotNull($event);
        $this->assertArrayNotHasKey('suite', $event->payload['data']['reservation']);

        Carbon::setTestNow();
    }

    public function test_cancelling_a_single_suite_room_is_always_refused(): void
    {
        Carbon::setTestNow('2026-08-16');
        [, $memberA, $memberB] = $this->makeSuiteFixture();

        [$reservation, $resRooms] = $this->makeReservationWithRooms([$memberA, $memberB], '2026-08-16', '2026-08-18');

        $user = $this->userWithApiPermissions(['view reservations', 'cancel reservations']);
        $response = $this->actingAs($user, 'api')->postJson(
            "/api/users/reservations/{$reservation->id}/rooms/{$resRooms[0]->id}/cancel",
            [],
        );

        $response->assertStatus(500);
        $response->assertJsonPath('success', false);
        $this->assertDatabaseHas('reservation_rooms', ['id' => $resRooms[0]->id, 'status' => ReservationRoom::STATUS_ACTIVE]);

        Carbon::setTestNow();
    }

    public function test_moving_a_suite_room_sends_a_debounced_reservation_updated_not_room_moved(): void
    {
        Carbon::setTestNow('2026-08-16');
        [$suite, $memberA, $memberB] = $this->makeSuiteFixture();
        $hotelId = (int) $memberA->building->hotel_id;
        DB::table('hotels')->where('id', $hotelId)->update(['pms_protocol' => 'v1']);

        // A second suite to move into (whole-suite move keeps the marker).
        $buildingId = $memberA->building_id;
        $floorId = $memberA->floor_id;
        $roomTypeId = $memberA->room_type_id;
        $suffix = $this->uniqueSuffix();
        $otherSuite = Suite::create(['building_id' => $buildingId, 'floor_id' => $floorId, 'number' => 'ST2'.$suffix, 'suiteStatus' => 0, 'active' => 1]);
        $otherMember = $this->createRoom($buildingId, $floorId, $roomTypeId, 'SC'.$suffix, $otherSuite->id);

        [$reservation, $resRooms] = $this->makeReservationWithRooms([$memberA], '2026-08-16', '2026-08-18');

        $user = $this->userWithApiPermissions(['view reservations', 'cancel reservations']);
        $response = $this->actingAs($user, 'api')->postJson(
            "/api/users/reservations/{$reservation->id}/rooms/{$resRooms[0]->id}/move",
            ['new_room_id' => $otherMember->id, 'reprice' => false],
        );
        $response->assertOk();

        $event = GrmsOutboxEvent::where('entity_id', (string) $reservation->id)->latest('id')->first();
        $this->assertNotNull($event);
        $this->assertSame('reservation.updated', $event->type);
        $this->assertTrue($event->next_attempt_at->greaterThan(now()->addSeconds(3)));

        Carbon::setTestNow();
    }

    public function test_snapshot_lists_suites_with_their_member_rooms(): void
    {
        [$suite, $memberA, $memberB] = $this->makeSuiteFixture();
        $hotelId = (int) $memberA->building->hotel_id;
        DB::table('hotels')->where('id', $hotelId)->update(['pms_protocol' => 'v1']);

        $credentialService = app(\App\Services\Grms\GrmsCredentialService::class);
        $result = $credentialService->generate($hotelId, \App\Models\GrmsHotelCredential::DIRECTION_INBOUND);
        $signer = app(\App\Services\Grms\GrmsSignatureService::class);
        $timestamp = (string) time();
        $signature = $signer->sign($timestamp, '', $result['secret']);

        $response = $this->call(
            'GET',
            '/api/grms/v1/snapshot',
            [], [], [],
            $this->transformHeadersToServerVars([
                'X-Key-Id' => $result['credential']->key_id,
                'X-Timestamp' => $timestamp,
                'X-Signature' => $signature,
            ]),
        );

        $response->assertStatus(200);
        $suites = collect($response->json('suites'));
        $found = $suites->firstWhere('id', (string) $suite->id);
        $this->assertNotNull($found);
        $this->assertEqualsCanonicalizing([$memberA->number, $memberB->number], $found['rooms']);
    }
}
