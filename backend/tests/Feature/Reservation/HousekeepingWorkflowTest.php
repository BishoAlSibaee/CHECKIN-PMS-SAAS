<?php

namespace Tests\Feature\Reservation;

use App\Models\Client;
use App\Models\Reservation;
use App\Models\ReservationRoom;
use App\Models\Reservation_source;
use App\Models\Room;
use App\Models\Stay_reason;
use App\Services\ReservationRoomStatusService;
use Carbon\Carbon;
use Database\Seeders\ReservationTestDataSeeder;
use Tests\TestCase;

class HousekeepingWorkflowTest extends TestCase
{
    private const TEST_NOW = '2026-06-15';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(self::TEST_NOW);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_checkout_rejected_when_outstanding_balance(): void
    {
        $ctx = $this->seedAndCreateStay();
        $user = $ctx['user'];
        $reservation = $ctx['reservation'];

        $checkIn = $this->actingAs($user, 'api')->patchJson(
            "/api/users/reservations/{$reservation->id}",
            ['logedin' => 1]
        );
        $checkIn->assertStatus(200);
        $checkIn->assertJsonPath('data.reservation.logedin', 1);

        $checkOut = $this->actingAs($user, 'api')->patchJson(
            "/api/users/reservations/{$reservation->id}",
            ['logedin' => 0]
        );

        $checkOut->assertStatus(422);
        $checkOut->assertJsonPath('success', false);
        $this->assertStringContainsString(
            'Outstanding balance',
            $checkOut->json('message') ?? ''
        );

        $this->assertDatabaseHas('reservations', [
            'id' => $reservation->id,
            'logedin' => 1,
        ]);
    }

    public function test_payment_allowed_after_scheduled_departure(): void
    {
        $ctx = $this->seedAndCreateStay();
        $user = $ctx['user'];
        $reservation = $ctx['reservation'];

        $this->actingAs($user, 'api')->patchJson(
            "/api/users/reservations/{$reservation->id}",
            ['logedin' => 1]
        )->assertStatus(200);

        Carbon::setTestNow('2026-06-20');

        $reservation->refresh();
        $amount = min(100.0, $reservation->balanceDue());

        $response = $this->actingAs($user, 'api')->postJson(
            "/api/users/reservations/{$reservation->id}/payments",
            ['pay' => $amount, 'type' => 0]
        );

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
    }

    public function test_checkout_allowed_after_expire_when_fully_paid(): void
    {
        $ctx = $this->seedAndCreateStay();
        $user = $ctx['user'];
        $reservation = $ctx['reservation'];

        $this->payReservationInFull($user, $reservation);

        $this->actingAs($user, 'api')->patchJson(
            "/api/users/reservations/{$reservation->id}",
            ['logedin' => 1]
        )->assertStatus(200);

        Carbon::setTestNow('2026-06-20');

        $checkOut = $this->actingAs($user, 'api')->patchJson(
            "/api/users/reservations/{$reservation->id}",
            ['logedin' => 0]
        );

        $checkOut->assertStatus(200);
        $checkOut->assertJsonPath('data.reservation.logedin', 0);
    }

    public function test_checkout_sets_room_to_preparation(): void
    {
        $ctx = $this->seedAndCreateStay();
        $user = $ctx['user'];
        $reservation = $ctx['reservation'];
        $room = $ctx['room'];

        $this->payReservationInFull($user, $reservation);

        $checkIn = $this->actingAs($user, 'api')->patchJson(
            "/api/users/reservations/{$reservation->id}",
            ['logedin' => 1]
        );
        $checkIn->assertStatus(200);
        $checkIn->assertJsonPath('data.reservation.logedin', 1);

        $checkOut = $this->actingAs($user, 'api')->patchJson(
            "/api/users/reservations/{$reservation->id}",
            ['logedin' => 0]
        );
        $checkOut->assertStatus(200);
        $checkOut->assertJsonPath('data.reservation.logedin', 0);

        $this->assertDatabaseHas('rooms', [
            'id' => $room->id,
            'roomStatus' => ReservationRoomStatusService::ROOM_PREPARATION,
        ]);
    }

    public function test_check_in_blocked_when_room_needs_cleaning(): void
    {
        $ctx = $this->seedAndCreateStay(logedin: 0);
        $user = $ctx['user'];
        $reservation = $ctx['reservation'];
        $room = $ctx['room'];

        Room::where('id', $room->id)->update([
            'roomStatus' => ReservationRoomStatusService::ROOM_PREPARATION,
        ]);
        $room = $room->fresh();

        $response = $this->actingAs($user, 'api')->patchJson(
            "/api/users/reservations/{$reservation->id}",
            ['logedin' => 1]
        );

        $response->assertJsonPath('success', false);
        $this->assertStringContainsString('cleaning', strtolower($response->json('message') ?? ''));
    }

    public function test_booking_allowed_on_room_needing_cleaning(): void
    {
        $ctx = $this->seedContext();
        $user = $ctx['user'];
        $room = $this->findFreeRoom('2026-10-01', '2026-10-05');
        Room::where('id', $room->id)->update([
            'roomStatus' => ReservationRoomStatusService::ROOM_PREPARATION,
        ]);
        $room = $room->fresh();

        $response = $this->actingAs($user, 'api')->postJson('/api/users/makeReservation', [
            'client_id' => $ctx['client']->id,
            'rooms' => [['room_id' => $room->id]],
            'start_date' => '2026-10-01',
            'expire_date' => '2026-10-05',
            'reservation_type' => 0,
            'reservation_status' => 1,
            'stay_reason_id' => $ctx['stayReason']->id,
            'reservation_source_id' => $ctx['source']->id,
            'rent_type' => 0,
            'price_calculation_mode' => 0,
            'discount' => 0,
            'extras' => 0,
            'penalties' => 0,
            'pay_amount' => 0,
            'logedin' => 0,
        ]);

        $body = $response->json();
        $this->assertTrue($response->status() === 200 && ($body['success'] ?? false), $body['message'] ?? $response->getContent());
    }

    public function test_check_in_allowed_after_room_marked_ready(): void
    {
        $ctx = $this->seedAndCreateStay(logedin: 0);
        $user = $this->userWithApiPermissions(['manage rooms']);
        $reservation = $ctx['reservation'];
        $room = $ctx['room'];

        Room::where('id', $room->id)->update([
            'roomStatus' => ReservationRoomStatusService::ROOM_PREPARATION,
        ]);
        $room = $room->fresh();

        $markReady = $this->actingAs($user, 'api')->postJson('/api/users/updateRoom', [
            'id' => $room->id,
            'roomStatus' => ReservationRoomStatusService::ROOM_AVAILABLE,
        ]);
        $markReady->assertStatus(200);

        $checkIn = $this->actingAs($user, 'api')->patchJson(
            "/api/users/reservations/{$reservation->id}",
            ['logedin' => 1]
        );
        $checkIn->assertStatus(200);
        $checkIn->assertJsonPath('data.reservation.logedin', 1);

        $this->assertDatabaseHas('rooms', [
            'id' => $room->id,
            'roomStatus' => ReservationRoomStatusService::ROOM_OCCUPIED,
        ]);
    }

    public function test_booking_room_availability_allows_dirty_room_with_flag(): void
    {
        $ctx = $this->seedContext();
        $user = $ctx['user'];
        $room = $this->findFreeRoom('2026-10-01', '2026-10-05');
        Room::where('id', $room->id)->update([
            'roomStatus' => ReservationRoomStatusService::ROOM_PREPARATION,
        ]);
        $room = $room->fresh();

        $response = $this->actingAs($user, 'api')->getJson(
            '/api/users/booking-room-availability?' . http_build_query([
                'building_id' => $room->building_id,
                'start_date' => '2026-10-01',
                'expire_date' => '2026-10-05',
            ])
        );

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);

        $rooms = collect($response->json('data.rooms') ?? []);
        $row = $rooms->firstWhere('id', $room->id);

        $this->assertNotNull($row);
        $this->assertTrue($row['available_for_period']);
        $this->assertTrue($row['needs_cleaning_before_checkin']);
    }

    private function seedContext(): array
    {
        $this->artisan('db:seed', [
            '--class' => ReservationTestDataSeeder::class,
            '--force' => true,
            '--no-interaction' => true,
        ]);

        $user = $this->userWithApiPermissions(['manage rooms']);

        return [
            'user' => $user,
            'client' => Client::first(),
            'stayReason' => Stay_reason::first(),
            'source' => Reservation_source::first(),
        ];
    }

    private function seedAndCreateStay(int $logedin = 0): array
    {
        $ctx = $this->seedContext();
        $start = self::TEST_NOW;
        $end = '2026-06-18';
        $room = $this->findFreeRoom($start, $end);

        $response = $this->actingAs($ctx['user'], 'api')->postJson('/api/users/makeReservation', [
            'client_id' => $ctx['client']->id,
            'rooms' => [['room_id' => $room->id]],
            'start_date' => $start,
            'expire_date' => $end,
            'reservation_type' => 0,
            'reservation_status' => 1,
            'stay_reason_id' => $ctx['stayReason']->id,
            'reservation_source_id' => $ctx['source']->id,
            'rent_type' => 0,
            'price_calculation_mode' => 0,
            'discount' => 0,
            'extras' => 0,
            'penalties' => 0,
            'pay_amount' => 0,
            'logedin' => $logedin,
        ]);

        $body = $response->json();
        $this->assertTrue($response->status() === 200 && ($body['success'] ?? false), $body['message'] ?? $response->getContent());

        $reservationId = $body['data']['id'] ?? $body['data']['reservation']['id'] ?? null;
        $reservation = Reservation::findOrFail($reservationId);

        return array_merge($ctx, [
            'room' => $room->fresh(),
            'reservation' => $reservation,
        ]);
    }

    private function findFreeRoom(string $start, string $end): Room
    {
        $roomStatusService = app(ReservationRoomStatusService::class);

        foreach (Room::where('active', 1)->whereHas('roomType')->get() as $candidate) {
            // A room with a guest still checked in blocks ANY future booking
            // (not just overlapping dates) until checkout is registered —
            // same date-blind rule ReservationController::makeReservation()
            // enforces.
            if ($roomStatusService->findActiveInHouseReservationId($candidate->id) !== null) {
                continue;
            }

            $overlap = ReservationRoom::where('room_id', $candidate->id)
                ->whereHas('reservation', function ($query) use ($start, $end) {
                    $query->whereNotIn('reservation_status', Reservation::nonBlockingInventoryStatuses())
                        ->where('start_date', '<', $end)
                        ->where('expire_date', '>', $start);
                })->exists();

            if (!$overlap) {
                Room::where('id', $candidate->id)->update([
                    'roomStatus' => ReservationRoomStatusService::ROOM_AVAILABLE,
                ]);

                return $candidate->fresh();
            }
        }

        $this->fail('No free room for test dates');
    }

    private function payReservationInFull($user, Reservation $reservation): void
    {
        $reservation->refresh();
        $amount = (float) $reservation->total;

        $response = $this->actingAs($user, 'api')->postJson(
            "/api/users/reservations/{$reservation->id}/payments",
            ['pay' => $amount, 'type' => 0]
        );

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
    }
}
