<?php

namespace Tests\Feature\Reservation;

use App\Models\Client;
use App\Models\Hotel;
use App\Models\Reservation;
use App\Models\ReservationChangeLog;
use App\Models\ReservationDailyCharge;
use App\Models\ReservationRoom;
use App\Models\ReservationSource;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\Stay_reason;
use App\Models\User;
use App\Services\ReservationRoomStatusService;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Cancelling one room within a still-active multi-room reservation, leaving
 * the rest of the stay untouched — previously only whole-reservation cancel
 * existed. Uses a fully isolated hotel/building/room-type/rooms fixture
 * (same reasoning as GroupBookingTest / ChannexBookingIngestionTest): this
 * DB is real and never reset between test runs.
 */
class PerRoomCancelTest extends TestCase
{
    use \Illuminate\Foundation\Testing\DatabaseTransactions;

    protected function connectionsToTransact(): array
    {
        return ['mysql', 'mysql2'];
    }

    /** @return array{0: int, 1: Room, 2: Room, 3: int, 4: int, 5: User} */
    private function seedFixtures(): array
    {
        $marker = 'Per Room Cancel Test '.uniqid();

        $hotel = Hotel::create(['name_ar' => $marker, 'name_en' => $marker, 'is_active' => true]);
        $building = \App\Models\Building::create(['hotel_id' => $hotel->id, 'number' => 'B1', 'name' => $marker]);
        $floor = \App\Models\Floor::create(['building_id' => $building->id, 'number' => '1', 'active' => 1]);

        $roomType = RoomType::create([
            'name_ar' => $marker,
            'name_en' => $marker,
            'description' => 'Per-room cancel test fixture',
            'Min_daily_price' => 150,
            'Max_daily_price' => 250,
            'Min_monthly_price' => 3500,
            'Max_monthly_price' => 5500,
            'active_type' => 0,
        ]);

        $makeRoom = fn () => Room::unguarded(fn () => Room::create([
            'number' => 'PR'.substr((string) microtime(true), -6).random_int(10, 99),
            'building_id' => $building->id,
            'floor_id' => $floor->id,
            'suite_id' => null,
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

        foreach (['view reservations', 'create reservations', 'cancel reservations'] as $perm) {
            Permission::findOrCreate($perm, 'api');
        }
        $role = Role::create(['name' => 'per-room-cancel-role-'.uniqid(), 'guard_name' => 'api']);
        $role->syncPermissions(['view reservations', 'create reservations', 'cancel reservations']);

        $user = User::create([
            'name' => 'Room Cancel Staff '.uniqid(),
            'job_number' => 'PRC-'.uniqid(),
            'email' => 'prc.'.uniqid().'@example.com',
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
    private function makeTwoRoomReservation(int $hotelId, Room $roomA, Room $roomB, int $stayReasonId, int $sourceId, User $user, string $start = '2027-07-10', string $end = '2027-07-12'): array
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
            ]);

        $response->assertOk();
        $reservationId = $response->json('data.reservation.id');
        $reservation = Reservation::with('reservationRooms')->findOrFail($reservationId);
        $resRoomA = $reservation->reservationRooms->firstWhere('room_id', $roomA->id);
        $resRoomB = $reservation->reservationRooms->firstWhere('room_id', $roomB->id);

        return [$reservation, $resRoomA, $resRoomB];
    }

    public function test_cancel_one_room_keeps_the_other_active_and_recomputes_totals(): void
    {
        [$hotelId, $roomA, $roomB, $stayReasonId, $sourceId, $user] = $this->seedFixtures();
        [$reservation, $resRoomA, $resRoomB] = $this->makeTwoRoomReservation($hotelId, $roomA, $roomB, $stayReasonId, $sourceId, $user);

        $response = $this->actingAs($user, 'api')
            ->withHeader('X-Hotel-Id', (string) $hotelId)
            ->postJson("/api/users/reservations/{$reservation->id}/rooms/{$resRoomA->id}/cancel", ['reason' => 'Guest changed plans']);

        $response->assertOk();

        $resRoomA->refresh();
        $resRoomB->refresh();
        $this->assertTrue(ReservationRoom::isCancelled((int) $resRoomA->status));
        $this->assertFalse(ReservationRoom::isCancelled((int) $resRoomB->status));

        // Reservation itself stays confirmed, not cancelled.
        $reservation->refresh();
        $this->assertFalse(Reservation::isCancelled((int) $reservation->reservation_status));

        // Room A's daily charges are gone; room B's remain.
        $this->assertSame(0, ReservationDailyCharge::where('reservation_room_id', $resRoomA->id)->count());
        $this->assertGreaterThan(0, ReservationDailyCharge::where('reservation_room_id', $resRoomB->id)->count());

        // Total now reflects only room B's remaining base price.
        $remainingBase = (float) ReservationDailyCharge::where('reservation_id', $reservation->id)->sum('base_amount');
        $this->assertEqualsWithDelta($remainingBase, (float) $reservation->base_price, 0.05);
    }

    public function test_cancelling_the_last_active_room_is_refused(): void
    {
        [$hotelId, $roomA, $roomB, $stayReasonId, $sourceId, $user] = $this->seedFixtures();
        [$reservation, $resRoomA, $resRoomB] = $this->makeTwoRoomReservation($hotelId, $roomA, $roomB, $stayReasonId, $sourceId, $user);

        $this->actingAs($user, 'api')
            ->withHeader('X-Hotel-Id', (string) $hotelId)
            ->postJson("/api/users/reservations/{$reservation->id}/rooms/{$resRoomA->id}/cancel")
            ->assertOk();

        $response = $this->actingAs($user, 'api')
            ->withHeader('X-Hotel-Id', (string) $hotelId)
            ->postJson("/api/users/reservations/{$reservation->id}/rooms/{$resRoomB->id}/cancel");

        $response->assertJsonPath('success', false);
        $this->assertStringContainsString('last active room', $response->json('message'));

        $resRoomB->refresh();
        $this->assertFalse(ReservationRoom::isCancelled((int) $resRoomB->status));
    }

    /**
     * Known limitation, documented in ReservationController::cancelRoom():
     * no per-room check-in tracking exists, so this blocks ALL per-room
     * cancels while the whole reservation is checked in.
     */
    public function test_cancel_room_is_refused_while_reservation_is_checked_in(): void
    {
        [$hotelId, $roomA, $roomB, $stayReasonId, $sourceId, $user] = $this->seedFixtures();
        [$reservation, $resRoomA] = $this->makeTwoRoomReservation($hotelId, $roomA, $roomB, $stayReasonId, $sourceId, $user);

        $reservation->update(['logedin' => Reservation::LOGEDIN_IN_HOUSE]);

        $response = $this->actingAs($user, 'api')
            ->withHeader('X-Hotel-Id', (string) $hotelId)
            ->postJson("/api/users/reservations/{$reservation->id}/rooms/{$resRoomA->id}/cancel");

        $response->assertJsonPath('success', false);

        $resRoomA->refresh();
        $this->assertFalse(ReservationRoom::isCancelled((int) $resRoomA->status));
    }

    /**
     * The actual regression test for the availability-query fix: cancelling
     * room A must release it for a brand-new booking, even though the
     * original reservation (still holding room B) is not itself cancelled.
     */
    public function test_cancelling_a_room_releases_it_for_a_new_overlapping_booking(): void
    {
        [$hotelId, $roomA, $roomB, $stayReasonId, $sourceId, $user] = $this->seedFixtures();
        [$reservation, $resRoomA] = $this->makeTwoRoomReservation($hotelId, $roomA, $roomB, $stayReasonId, $sourceId, $user, '2027-08-10', '2027-08-12');

        $this->actingAs($user, 'api')
            ->withHeader('X-Hotel-Id', (string) $hotelId)
            ->postJson("/api/users/reservations/{$reservation->id}/rooms/{$resRoomA->id}/cancel")
            ->assertOk();

        $newClient = $this->makeClient();
        $rebook = $this->actingAs($user, 'api')
            ->withHeader('X-Hotel-Id', (string) $hotelId)
            ->postJson('/api/users/makeReservation', [
                'client_id' => $newClient->id,
                'rooms' => [['room_id' => $roomA->id]],
                'start_date' => '2027-08-10',
                'expire_date' => '2027-08-12',
                'reservation_type' => 0,
                'reservation_status' => Reservation::STATUS_CONFIRMED,
                'stay_reason_id' => $stayReasonId,
                'reservation_source_id' => $sourceId,
                'rent_type' => 0,
                'price_calculation_mode' => 0,
            ]);

        $rebook->assertOk();
        $rebook->assertJsonPath('success', true);
    }

    public function test_room_cancel_is_recorded_in_the_change_log(): void
    {
        [$hotelId, $roomA, $roomB, $stayReasonId, $sourceId, $user] = $this->seedFixtures();
        [$reservation, $resRoomA] = $this->makeTwoRoomReservation($hotelId, $roomA, $roomB, $stayReasonId, $sourceId, $user);

        $this->actingAs($user, 'api')
            ->withHeader('X-Hotel-Id', (string) $hotelId)
            ->postJson("/api/users/reservations/{$reservation->id}/rooms/{$resRoomA->id}/cancel")
            ->assertOk();

        $this->assertTrue(
            ReservationChangeLog::where('reservation_id', $reservation->id)
                ->where('action', 'room_cancelled')
                ->exists()
        );
    }
}
