<?php

namespace Tests\Feature\Reservation;

use App\Models\Client;
use App\Models\Reservation;
use App\Models\Reservation_source;
use App\Models\Room;
use App\Models\Stay_reason;
use App\Services\ReservationRoomStatusService;
use Carbon\Carbon;
use Database\Seeders\ReservationTestDataSeeder;
use Tests\TestCase;

class ReservationCheckInOccupancyTest extends TestCase
{
    use \Illuminate\Foundation\Testing\DatabaseTransactions;

    protected function connectionsToTransact(): array
    {
        return ['mysql', 'mysql2'];
    }

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-06-29');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_check_in_heals_stale_occupied_room_status(): void
    {
        $this->seedReservationFixtures();

        $user = $this->userWithApiPermissions(['view reservations', 'update reservations']);
        $room = $this->findOrCreateAvailableRoom('2026-06-29', '2026-06-30');
        $this->assertNotNull($room);

        $reservationId = $this->createReservationId($user, $room, '2026-06-29', '2026-06-30');
        Room::where('id', $room->id)->update(['roomStatus' => ReservationRoomStatusService::ROOM_OCCUPIED]);

        $checkIn = $this->actingAs($user, 'api')->patchJson("/api/users/reservations/{$reservationId}", [
            'logedin' => 1,
            'login_time' => '2026-06-29',
        ]);

        $checkIn->assertOk();
        $checkIn->assertJsonPath('success', true);
        $this->assertDatabaseHas('reservations', ['id' => $reservationId, 'logedin' => 1]);
        $this->assertDatabaseHas('rooms', ['id' => $room->id, 'roomStatus' => ReservationRoomStatusService::ROOM_OCCUPIED]);
    }

    public function test_check_in_blocked_when_another_guest_is_in_house_even_after_scheduled_departure(): void
    {
        $this->seedReservationFixtures();

        $user = $this->userWithApiPermissions(['view reservations', 'update reservations']);
        $room = $this->findOrCreateAvailableRoom('2026-06-28', '2026-06-30');
        $this->assertNotNull($room);

        Carbon::setTestNow('2026-06-28');
        $targetId = $this->createReservationId($user, $room, '2026-06-29', '2026-06-30');
        $blockingId = $this->createReservationId($user, $room, '2026-06-28', '2026-06-29');
        $this->actingAs($user, 'api')->patchJson("/api/users/reservations/{$blockingId}", [
            'logedin' => 1,
            'login_time' => '2026-06-28',
        ])->assertOk();

        Carbon::setTestNow('2026-06-29');

        $checkIn = $this->actingAs($user, 'api')->patchJson("/api/users/reservations/{$targetId}", [
            'logedin' => 1,
            'login_time' => '2026-06-29',
        ]);

        $checkIn->assertJsonPath('success', false);
        $message = (string) $checkIn->json('message');
        $this->assertStringContainsString('occupied', strtolower($message));
        $this->assertStringContainsString('checked out', strtolower($message));
        $this->assertStringContainsString((string) $blockingId, $message);
        $this->assertDatabaseHas('reservations', ['id' => $targetId, 'logedin' => 0]);
    }

    public function test_booking_availability_marks_in_house_room_unavailable_for_future_dates(): void
    {
        $this->seedReservationFixtures();

        $user = $this->userWithApiPermissions(['view reservations', 'update reservations']);
        Carbon::setTestNow('2026-06-28');
        $room = $this->findOrCreateAvailableRoom('2026-06-28', '2026-06-29');
        $this->assertNotNull($room);

        $blockingId = $this->createReservationId($user, $room, '2026-06-28', '2026-06-29');
        $this->actingAs($user, 'api')->patchJson("/api/users/reservations/{$blockingId}", [
            'logedin' => 1,
            'login_time' => '2026-06-28',
        ])->assertOk();

        Carbon::setTestNow('2026-06-29');

        $response = $this->actingAs($user, 'api')->getJson(
            '/api/users/booking-room-availability?' . http_build_query([
                'building_id' => $room->building_id,
                'start_date' => '2026-06-29',
                'expire_date' => '2026-06-30',
            ])
        );

        $response->assertOk();
        $rows = collect($response->json('data.rooms') ?? []);
        $row = $rows->firstWhere('id', $room->id);
        $this->assertNotNull($row);
        $this->assertFalse($row['available_for_period']);
        $this->assertSame('still_checked_in', $row['unavailable_reason']);
        $this->assertSame($blockingId, $row['conflict']['reservation_id'] ?? null);
    }

    public function test_booking_availability_blocks_room_while_previous_guest_has_not_checked_out(): void
    {
        $this->seedReservationFixtures();

        $user = $this->userWithApiPermissions(['view reservations', 'update reservations', 'create reservations']);
        Carbon::setTestNow('2026-06-01');
        $room = $this->findOrCreateAvailableRoom('2026-06-01', '2026-06-05');
        $this->assertNotNull($room);

        $pastId = $this->createReservationId($user, $room, '2026-06-01', '2026-06-05');
        $this->actingAs($user, 'api')->patchJson("/api/users/reservations/{$pastId}", [
            'logedin' => 1,
            'login_time' => '2026-06-01',
        ])->assertOk();

        Carbon::setTestNow('2026-06-10');

        $response = $this->actingAs($user, 'api')->getJson(
            '/api/users/booking-room-availability?' . http_build_query([
                'building_id' => $room->building_id,
                'start_date' => '2026-06-20',
                'expire_date' => '2026-06-25',
            ])
        );

        $response->assertOk();
        $row = collect($response->json('data.rooms') ?? [])->firstWhere('id', $room->id);
        $this->assertNotNull($row);
        $this->assertFalse($row['available_for_period'], 'Still-checked-in guest must block later booking.');
        $this->assertSame('still_checked_in', $row['unavailable_reason']);
        $this->assertSame($pastId, $row['conflict']['reservation_id'] ?? null);

        $blockedCreate = $this->actingAs($user, 'api')->postJson('/api/users/makeReservation', [
            'client_id' => Client::first()->id,
            'rooms' => [['room_id' => $room->id]],
            'start_date' => '2026-06-20',
            'expire_date' => '2026-06-25',
            'reservation_type' => 0,
            'reservation_status' => 1,
            'stay_reason_id' => Stay_reason::first()->id,
            'reservation_source_id' => Reservation_source::first()->id,
            'rent_type' => 0,
            'price_calculation_mode' => 0,
            'discount' => 0,
            'extras' => 0,
            'penalties' => 0,
            'pay_amount' => 0,
            'logedin' => 0,
        ]);

        $blockedCreate->assertJsonPath('success', false);
        $message = (string) $blockedCreate->json('message');
        $this->assertStringContainsString((string) $pastId, $message);
        $this->assertTrue(
            str_contains($message, 'لم يسجّل المغادرة') || str_contains(strtolower($message), 'check out'),
            $message
        );
    }

    public function test_booking_availability_ignores_confirmed_reservation_that_ended_before_selected_period(): void
    {
        $this->seedReservationFixtures();

        $user = $this->userWithApiPermissions(['view reservations', 'update reservations']);
        $room = $this->findOrCreateAvailableRoom('2026-06-20', '2026-06-25');
        $this->assertNotNull($room);

        Carbon::setTestNow('2026-06-01');
        $this->createReservationId($user, $room, '2026-06-01', '2026-06-05');

        Carbon::setTestNow('2026-06-10');

        $response = $this->actingAs($user, 'api')->getJson(
            '/api/users/booking-room-availability?' . http_build_query([
                'building_id' => $room->building_id,
                'start_date' => '2026-06-20',
                'expire_date' => '2026-06-25',
            ])
        );

        $response->assertOk();
        $row = collect($response->json('data.rooms') ?? [])->firstWhere('id', $room->id);
        $this->assertNotNull($row);
        $this->assertTrue($row['available_for_period']);
        $this->assertNull($row['conflict']);
    }

    private function seedReservationFixtures(): void
    {
        $this->artisan('db:seed', [
            '--class' => ReservationTestDataSeeder::class,
            '--force' => true,
            '--no-interaction' => true,
        ]);
    }

    private function createReservationId($user, Room $room, string $start, string $end): int
    {
        $client = Client::first();
        $stayReason = Stay_reason::first();
        $source = Reservation_source::first();

        $response = $this->actingAs($user, 'api')->postJson('/api/users/makeReservation', [
            'client_id' => $client->id,
            'rooms' => [['room_id' => $room->id]],
            'start_date' => $start,
            'expire_date' => $end,
            'reservation_type' => 0,
            'reservation_status' => 1,
            'stay_reason_id' => $stayReason->id,
            'reservation_source_id' => $source->id,
            'rent_type' => 0,
            'price_calculation_mode' => 0,
            'discount' => 0,
            'extras' => 0,
            'penalties' => 0,
            'pay_amount' => 0,
            'logedin' => 0,
        ]);

        $response->assertOk();
        $id = $response->json('data.reservation.id') ?? $response->json('data.id');
        $this->assertNotNull($id);

        return (int) $id;
    }
}
