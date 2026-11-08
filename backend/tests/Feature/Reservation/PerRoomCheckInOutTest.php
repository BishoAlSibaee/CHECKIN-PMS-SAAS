<?php

namespace Tests\Feature\Reservation;

use App\Models\Client;
use App\Models\Hotel;
use App\Models\Reservation;
use App\Models\ReservationChangeLog;
use App\Models\ReservationRoom;
use App\Models\ReservationSource;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\Stay_reason;
use App\Models\Suite;
use App\Models\User;
use App\Services\ReservationRoomStatusService;
use Carbon\Carbon;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Per-room check-in/check-out (see ReservationRoomStatusService::
 * recalculateAggregateLogedin) — checking a single room in or out of a
 * multi-room reservation independently of its other rooms, the gap this
 * whole feature was built to close. Fixture style mirrors PerRoomCancelTest.
 */
class PerRoomCheckInOutTest extends TestCase
{
    use \Illuminate\Foundation\Testing\DatabaseTransactions;

    protected function connectionsToTransact(): array
    {
        return ['mysql', 'mysql2'];
    }

    /** @return array{0: int, 1: Room, 2: Room, 3: int, 4: int, 5: User} */
    private function seedFixtures(): array
    {
        $marker = 'Per Room CheckInOut Test '.uniqid();

        $hotel = Hotel::create(['name_ar' => $marker, 'name_en' => $marker, 'is_active' => true]);
        $building = \App\Models\Building::create(['hotel_id' => $hotel->id, 'number' => 'B1', 'name' => $marker]);
        $floor = \App\Models\Floor::create(['building_id' => $building->id, 'number' => '1', 'active' => 1]);

        $roomType = RoomType::create([
            'name_ar' => $marker,
            'name_en' => $marker,
            'description' => 'Per-room check-in/out test fixture',
            'Min_daily_price' => 150,
            'Max_daily_price' => 250,
            'Min_monthly_price' => 3500,
            'Max_monthly_price' => 5500,
            'active_type' => 0,
        ]);

        $makeRoom = fn (?int $suiteId = null) => Room::unguarded(fn () => Room::create([
            'number' => 'PI'.substr((string) microtime(true), -6).random_int(10, 99),
            'building_id' => $building->id,
            'floor_id' => $floor->id,
            'suite_id' => $suiteId,
            'room_type_id' => $roomType->id,
            'capacity' => 2,
            'active' => 1,
            'roomStatus' => ReservationRoomStatusService::ROOM_AVAILABLE,
        ]));
        $roomA = $makeRoom();
        $roomB = $makeRoom();

        $stayReason = Stay_reason::firstOrCreate(
            ['name_en' => 'Tourism'],
            ['name_ar' => 'سياحة', 'description' => 'Test stay reason']
        );
        $source = ReservationSource::create(['name_ar' => $marker, 'name_en' => $marker, 'description' => 'test']);

        $registrar = app(PermissionRegistrar::class);
        $previousTeam = $registrar->getPermissionsTeamId();
        $registrar->setPermissionsTeamId($hotel->id);

        foreach (['view reservations', 'create reservations', 'update reservations'] as $perm) {
            Permission::findOrCreate($perm, 'api');
        }
        $role = Role::create(['name' => 'per-room-checkinout-role-'.uniqid(), 'guard_name' => 'api']);
        $role->syncPermissions(['view reservations', 'create reservations', 'update reservations']);

        $user = User::create([
            'name' => 'Room CheckInOut Staff '.uniqid(),
            'job_number' => 'PRIO-'.uniqid(),
            'email' => 'prio.'.uniqid().'@example.com',
            'mobile' => '05'.substr(uniqid(), -8),
            'active' => 1,
            'password' => bcrypt('test-password'),
        ]);
        $user->hotels()->syncWithoutDetaching([$hotel->id]);
        $user->assignRole($role);

        $registrar->setPermissionsTeamId($previousTeam);

        return [(int) $hotel->id, $roomA, $roomB, (int) $stayReason->id, (int) $source->id, $user];
    }

    private function makeClient(): Client
    {
        return Client::create([
            'first_name' => 'Guest',
            'last_name' => uniqid(),
            'email' => 'guest.'.uniqid().'@example.com',
            'international_code' => '+966',
            'mobile' => '05'.substr(uniqid(), -8),
            'IdType' => 'PASSPORT',
            'IdNumber' => 'TEST-'.uniqid(),
            'gender' => 'MALE',
            'guest_type' => 'VISITOR',
        ]);
    }

    /** @return array{0: Reservation, 1: ReservationRoom, 2: ReservationRoom} */
    private function makeTwoRoomReservation(int $hotelId, Room $roomA, Room $roomB, int $stayReasonId, int $sourceId, User $user, string $start = '2027-09-10', string $end = '2027-09-12', bool $checkedIn = true): array
    {
        $client = $this->makeClient();

        $response = $this->actingAs($user, 'api')
            ->withHeader('X-Hotel-Id', (string) $hotelId)
            ->postJson('/api/users/makeReservation', [
                'client_id' => $client->id,
                'rooms' => [['room_id' => $roomA->id], ['room_id' => $roomB->id]],
                'start_date' => $start,
                'expire_date' => $end,
                'reservation_type' => 0,
                'reservation_status' => Reservation::STATUS_CONFIRMED,
                'stay_reason_id' => $stayReasonId,
                'reservation_source_id' => $sourceId,
                'rent_type' => 0,
                'price_calculation_mode' => 0,
                'logedin' => $checkedIn ? Reservation::LOGEDIN_IN_HOUSE : Reservation::LOGEDIN_NOT_IN_HOUSE,
            ]);

        $response->assertOk();
        $reservationId = $response->json('data.reservation.id');
        $reservation = Reservation::with('reservationRooms')->findOrFail($reservationId);
        $resRoomA = $reservation->reservationRooms->firstWhere('room_id', $roomA->id);
        $resRoomB = $reservation->reservationRooms->firstWhere('room_id', $roomB->id);

        return [$reservation, $resRoomA, $resRoomB];
    }

    public function test_checking_out_one_room_leaves_the_other_in_house(): void
    {
        Carbon::setTestNow('2027-09-10');
        [$hotelId, $roomA, $roomB, $stayReasonId, $sourceId, $user] = $this->seedFixtures();
        [$reservation, $resRoomA, $resRoomB] = $this->makeTwoRoomReservation($hotelId, $roomA, $roomB, $stayReasonId, $sourceId, $user);

        $response = $this->actingAs($user, 'api')
            ->withHeader('X-Hotel-Id', (string) $hotelId)
            ->postJson("/api/users/reservations/{$reservation->id}/rooms/{$resRoomA->id}/check-out");

        $response->assertOk();

        $resRoomA->refresh();
        $resRoomB->refresh();
        $this->assertSame(ReservationRoom::LOGEDIN_NOT_IN_HOUSE, (int) $resRoomA->logedin);
        $this->assertSame(ReservationRoom::LOGEDIN_IN_HOUSE, (int) $resRoomB->logedin);

        // The aggregate must stay in-house — room B is still occupied.
        $this->assertSame(Reservation::LOGEDIN_IN_HOUSE, (int) $reservation->fresh()->logedin);

        Carbon::setTestNow();
    }

    /**
     * The regression test for the exact bug raised during design: once room
     * A's OWN stay has ended and it was checked out, it must no longer look
     * "still occupied past checkout" just because room B (same reservation)
     * hasn't checked out yet — the old code asked the reservation's
     * aggregate `logedin`, which stayed 1 because of room B, and wrongly
     * blocked a same-day turnover booking for room A.
     */
    public function test_checked_out_room_is_no_longer_blocked_by_the_other_rooms_in_house_state(): void
    {
        Carbon::setTestNow('2027-09-10');
        [$hotelId, $roomA, $roomB, $stayReasonId, $sourceId, $user] = $this->seedFixtures();
        [$reservation, $resRoomA] = $this->makeTwoRoomReservation($hotelId, $roomA, $roomB, $stayReasonId, $sourceId, $user);

        Carbon::setTestNow('2027-09-12'); // the reservation's own departure day

        // Room A checks out on schedule; room B's guest hasn't left yet —
        // overdue relative to the same departure date.
        $this->actingAs($user, 'api')
            ->withHeader('X-Hotel-Id', (string) $hotelId)
            ->postJson("/api/users/reservations/{$reservation->id}/rooms/{$resRoomA->id}/check-out")
            ->assertOk();

        $this->assertNull(
            app(ReservationRoomStatusService::class)->findActiveInHouseReservationId((int) $roomA->id)
        );

        $newClient = $this->makeClient();
        $rebook = $this->actingAs($user, 'api')
            ->withHeader('X-Hotel-Id', (string) $hotelId)
            ->postJson('/api/users/makeReservation', [
                'client_id' => $newClient->id,
                'rooms' => [['room_id' => $roomA->id]],
                'start_date' => '2027-09-12',
                'expire_date' => '2027-09-14',
                'reservation_type' => 0,
                'reservation_status' => Reservation::STATUS_CONFIRMED,
                'stay_reason_id' => $stayReasonId,
                'reservation_source_id' => $sourceId,
                'rent_type' => 0,
                'price_calculation_mode' => 0,
            ]);

        $rebook->assertOk();
        $rebook->assertJsonPath('success', true);

        Carbon::setTestNow();
    }

    public function test_checking_out_the_last_active_room_fully_checks_out_the_reservation(): void
    {
        Carbon::setTestNow('2027-09-10');
        [$hotelId, $roomA, $roomB, $stayReasonId, $sourceId, $user] = $this->seedFixtures();
        [$reservation, $resRoomA, $resRoomB] = $this->makeTwoRoomReservation($hotelId, $roomA, $roomB, $stayReasonId, $sourceId, $user);

        $this->actingAs($user, 'api')
            ->withHeader('X-Hotel-Id', (string) $hotelId)
            ->postJson("/api/users/reservations/{$reservation->id}/rooms/{$resRoomA->id}/check-out")
            ->assertOk();

        $balance = $reservation->fresh()->balanceDue();
        if ($balance > 0.005) {
            $this->actingAs($user, 'api')
                ->withHeader('X-Hotel-Id', (string) $hotelId)
                ->postJson("/api/users/reservations/{$reservation->id}/payments", [
                    'pay' => round($balance, 2),
                    'type' => \App\Models\ReservationPay::TYPE_PAYMENT,
                ])
                ->assertOk();
        }

        $response = $this->actingAs($user, 'api')
            ->withHeader('X-Hotel-Id', (string) $hotelId)
            ->postJson("/api/users/reservations/{$reservation->id}/rooms/{$resRoomB->id}/check-out");

        $response->assertOk();

        $this->assertSame(Reservation::LOGEDIN_NOT_IN_HOUSE, (int) $reservation->fresh()->logedin);
        $this->assertTrue(
            ReservationChangeLog::where('reservation_id', $reservation->id)
                ->where('action', 'checked_out')
                ->exists()
        );

        Carbon::setTestNow();
    }

    public function test_checking_out_the_last_room_is_refused_with_an_outstanding_balance(): void
    {
        Carbon::setTestNow('2027-09-10');
        [$hotelId, $roomA, $roomB, $stayReasonId, $sourceId, $user] = $this->seedFixtures();
        [$reservation, $resRoomA, $resRoomB] = $this->makeTwoRoomReservation($hotelId, $roomA, $roomB, $stayReasonId, $sourceId, $user);

        $this->actingAs($user, 'api')
            ->withHeader('X-Hotel-Id', (string) $hotelId)
            ->postJson("/api/users/reservations/{$reservation->id}/rooms/{$resRoomA->id}/check-out")
            ->assertOk();

        $this->assertGreaterThan(0.0, $reservation->fresh()->balanceDue());

        $response = $this->actingAs($user, 'api')
            ->withHeader('X-Hotel-Id', (string) $hotelId)
            ->postJson("/api/users/reservations/{$reservation->id}/rooms/{$resRoomB->id}/check-out");

        $response->assertJsonPath('success', false);
        $resRoomB->refresh();
        $this->assertSame(ReservationRoom::LOGEDIN_IN_HOUSE, (int) $resRoomB->logedin);

        Carbon::setTestNow();
    }

    public function test_checking_out_a_suite_room_is_refused_while_its_suite_mate_is_on_the_same_reservation(): void
    {
        Carbon::setTestNow('2027-09-10');
        $marker = 'Suite CheckOut Test '.uniqid();
        $hotel = Hotel::create(['name_ar' => $marker, 'name_en' => $marker, 'is_active' => true]);
        $building = \App\Models\Building::create(['hotel_id' => $hotel->id, 'number' => 'B1', 'name' => $marker]);
        $floor = \App\Models\Floor::create(['building_id' => $building->id, 'number' => '1', 'active' => 1]);
        $roomType = RoomType::create([
            'name_ar' => $marker, 'name_en' => $marker, 'description' => 'Suite checkout fixture',
            'Min_daily_price' => 150, 'Max_daily_price' => 250,
            'Min_monthly_price' => 3500, 'Max_monthly_price' => 5500, 'active_type' => 0,
        ]);
        $suite = Suite::create(['building_id' => $building->id, 'floor_id' => $floor->id, 'number' => 'ST'.uniqid(), 'active' => 1]);

        [$hotelId, , , $stayReasonId, $sourceId, $user] = $this->seedFixturesForSuite($hotel, $building, $floor, $roomType);

        $roomA = Room::unguarded(fn () => Room::create([
            'number' => 'SA'.substr((string) microtime(true), -6).random_int(10, 99),
            'building_id' => $building->id, 'floor_id' => $floor->id, 'suite_id' => $suite->id,
            'room_type_id' => $roomType->id, 'capacity' => 2, 'active' => 1,
            'roomStatus' => ReservationRoomStatusService::ROOM_AVAILABLE,
        ]));
        $roomB = Room::unguarded(fn () => Room::create([
            'number' => 'SB'.substr((string) microtime(true), -6).random_int(10, 99),
            'building_id' => $building->id, 'floor_id' => $floor->id, 'suite_id' => $suite->id,
            'room_type_id' => $roomType->id, 'capacity' => 2, 'active' => 1,
            'roomStatus' => ReservationRoomStatusService::ROOM_AVAILABLE,
        ]));

        [$reservation, $resRoomA] = $this->makeTwoRoomReservation($hotelId, $roomA, $roomB, $stayReasonId, $sourceId, $user);

        $response = $this->actingAs($user, 'api')
            ->withHeader('X-Hotel-Id', (string) $hotelId)
            ->postJson("/api/users/reservations/{$reservation->id}/rooms/{$resRoomA->id}/check-out");

        $response->assertJsonPath('success', false);
        $this->assertStringContainsString('suite', $response->json('message'));

        Carbon::setTestNow();
    }

    /** @return array{0: int, 1: Room, 2: Room, 3: int, 4: int, 5: User} */
    private function seedFixturesForSuite(Hotel $hotel, $building, $floor, $roomType): array
    {
        $stayReason = Stay_reason::firstOrCreate(
            ['name_en' => 'Tourism'],
            ['name_ar' => 'سياحة', 'description' => 'Test stay reason']
        );
        $source = ReservationSource::create(['name_ar' => uniqid(), 'name_en' => uniqid(), 'description' => 'test']);

        $registrar = app(PermissionRegistrar::class);
        $previousTeam = $registrar->getPermissionsTeamId();
        $registrar->setPermissionsTeamId($hotel->id);

        foreach (['view reservations', 'create reservations', 'update reservations'] as $perm) {
            Permission::findOrCreate($perm, 'api');
        }
        $role = Role::create(['name' => 'per-room-checkinout-suite-role-'.uniqid(), 'guard_name' => 'api']);
        $role->syncPermissions(['view reservations', 'create reservations', 'update reservations']);

        $user = User::create([
            'name' => 'Suite CheckOut Staff '.uniqid(),
            'job_number' => 'PRIOS-'.uniqid(),
            'email' => 'prios.'.uniqid().'@example.com',
            'mobile' => '05'.substr(uniqid(), -8),
            'active' => 1,
            'password' => bcrypt('test-password'),
        ]);
        $user->hotels()->syncWithoutDetaching([$hotel->id]);
        $user->assignRole($role);

        $registrar->setPermissionsTeamId($previousTeam);

        $dummyRoom = Room::unguarded(fn () => Room::create([
            'number' => 'DUMMY'.uniqid(),
            'building_id' => $building->id, 'floor_id' => $floor->id, 'suite_id' => null,
            'room_type_id' => $roomType->id, 'capacity' => 2, 'active' => 1,
            'roomStatus' => ReservationRoomStatusService::ROOM_AVAILABLE,
        ]));

        return [(int) $hotel->id, $dummyRoom, $dummyRoom, (int) $stayReason->id, (int) $source->id, $user];
    }

    public function test_checking_in_the_first_room_logs_checked_in_and_sets_the_aggregate(): void
    {
        Carbon::setTestNow('2027-09-10');
        [$hotelId, $roomA, $roomB, $stayReasonId, $sourceId, $user] = $this->seedFixtures();
        [$reservation, $resRoomA, $resRoomB] = $this->makeTwoRoomReservation(
            $hotelId, $roomA, $roomB, $stayReasonId, $sourceId, $user, checkedIn: false
        );

        $this->assertSame(Reservation::LOGEDIN_NOT_IN_HOUSE, (int) $reservation->logedin);

        $response = $this->actingAs($user, 'api')
            ->withHeader('X-Hotel-Id', (string) $hotelId)
            ->postJson("/api/users/reservations/{$reservation->id}/rooms/{$resRoomA->id}/check-in");

        $response->assertOk();

        $resRoomA->refresh();
        $this->assertSame(ReservationRoom::LOGEDIN_IN_HOUSE, (int) $resRoomA->logedin);
        $this->assertSame(Reservation::LOGEDIN_IN_HOUSE, (int) $reservation->fresh()->logedin);
        $this->assertTrue(
            ReservationChangeLog::where('reservation_id', $reservation->id)
                ->where('action', 'checked_in')
                ->exists()
        );

        Carbon::setTestNow();
    }

    public function test_checking_in_a_second_room_is_logged_as_a_room_level_action(): void
    {
        Carbon::setTestNow('2027-09-10');
        [$hotelId, $roomA, $roomB, $stayReasonId, $sourceId, $user] = $this->seedFixtures();
        [$reservation, $resRoomA, $resRoomB] = $this->makeTwoRoomReservation(
            $hotelId, $roomA, $roomB, $stayReasonId, $sourceId, $user, checkedIn: false
        );

        $this->actingAs($user, 'api')
            ->withHeader('X-Hotel-Id', (string) $hotelId)
            ->postJson("/api/users/reservations/{$reservation->id}/rooms/{$resRoomA->id}/check-in")
            ->assertOk();

        $response = $this->actingAs($user, 'api')
            ->withHeader('X-Hotel-Id', (string) $hotelId)
            ->postJson("/api/users/reservations/{$reservation->id}/rooms/{$resRoomB->id}/check-in");

        $response->assertOk();

        $this->assertTrue(
            ReservationChangeLog::where('reservation_id', $reservation->id)
                ->where('action', 'room_checked_in')
                ->exists()
        );

        Carbon::setTestNow();
    }
}
