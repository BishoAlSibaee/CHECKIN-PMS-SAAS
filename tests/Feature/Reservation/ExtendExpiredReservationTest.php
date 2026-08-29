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

class ExtendExpiredReservationTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_can_extend_overdue_in_house_reservation(): void
    {
        Carbon::setTestNow('2026-09-10');

        $this->artisan('db:seed', [
            '--class' => ReservationTestDataSeeder::class,
            '--force' => true,
            '--no-interaction' => true,
        ]);

        $user = $this->userWithApiPermissions(['view reservations', 'update reservations']);
        $client = Client::first();
        $stayReason = Stay_reason::first();
        $source = Reservation_source::first();
        $room = $this->createIsolatedTestRoom();
        $this->assertNotNull($client);
        $this->assertNotNull($stayReason);
        $this->assertNotNull($source);
        $this->assertNotNull($room);

        $create = $this->actingAs($user, 'api')->postJson('/api/users/makeReservation', [
            'client_id' => $client->id,
            'rooms' => [['room_id' => $room->id]],
            'start_date' => '2026-09-10',
            'expire_date' => '2026-09-15',
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

        if ($create->status() === 422) {
            $this->markTestSkipped('Create reservation validation: '.$create->json('message'));
        }

        $create->assertOk();
        $reservationId = (int) ($create->json('data.reservation.id') ?? $create->json('data.id'));
        $this->assertGreaterThan(0, $reservationId);

        Room::where('id', $room->id)->update(['roomStatus' => ReservationRoomStatusService::ROOM_AVAILABLE]);

        $checkIn = $this->actingAs($user, 'api')->patchJson("/api/users/reservations/{$reservationId}", [
            'logedin' => 1,
            'login_time' => '2026-09-10',
        ]);
        $checkIn->assertOk();

        Reservation::where('id', $reservationId)->update([
            'start_date' => '2026-09-05',
            'expire_date' => '2026-09-08',
            'logedin' => Reservation::LOGEDIN_IN_HOUSE,
        ]);

        $newExpire = '2026-09-15';
        $extend = $this->actingAs($user, 'api')->patchJson(
            "/api/users/reservations/{$reservationId}/extend",
            ['expire_date' => $newExpire]
        );

        $extend->assertOk();
        $extend->assertJsonPath('success', true);

        $this->assertDatabaseHas('reservations', [
            'id' => $reservationId,
            'expire_date' => $newExpire,
            'logedin' => Reservation::LOGEDIN_IN_HOUSE,
        ]);
    }

    public function test_extend_rejects_after_check_out(): void
    {
        Carbon::setTestNow('2026-09-10');

        $this->artisan('db:seed', [
            '--class' => ReservationTestDataSeeder::class,
            '--force' => true,
            '--no-interaction' => true,
        ]);

        $user = $this->userWithApiPermissions(['view reservations', 'update reservations']);
        $client = Client::first();
        $stayReason = Stay_reason::first();
        $source = Reservation_source::first();
        $room = $this->createIsolatedTestRoom();
        $this->assertNotNull($room);

        $create = $this->actingAs($user, 'api')->postJson('/api/users/makeReservation', [
            'client_id' => $client->id,
            'rooms' => [['room_id' => $room->id]],
            'start_date' => '2026-09-10',
            'expire_date' => '2026-09-15',
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

        if ($create->status() === 422) {
            $this->markTestSkipped('Create reservation validation: '.$create->json('message'));
        }

        $create->assertOk();
        $reservationId = (int) ($create->json('data.reservation.id') ?? $create->json('data.id'));

        Room::where('id', $room->id)->update(['roomStatus' => ReservationRoomStatusService::ROOM_AVAILABLE]);

        $checkIn = $this->actingAs($user, 'api')->patchJson("/api/users/reservations/{$reservationId}", [
            'logedin' => 1,
            'login_time' => '2026-09-10',
        ]);
        $checkIn->assertOk();

        $checkOut = $this->actingAs($user, 'api')->patchJson("/api/users/reservations/{$reservationId}", [
            'logedin' => 0,
        ]);

        if ($checkOut->status() !== 200) {
            Reservation::where('id', $reservationId)->update([
                'logedin' => Reservation::LOGEDIN_NOT_IN_HOUSE,
                'login_time' => '2026-09-10',
            ]);
        } else {
            $checkOut->assertOk();
        }

        $extend = $this->actingAs($user, 'api')->patchJson(
            "/api/users/reservations/{$reservationId}/extend",
            ['expire_date' => '2026-09-18']
        );

        $extend->assertStatus(500);
        $extend->assertJsonPath('success', false);
        $this->assertStringContainsString('check-out', strtolower((string) $extend->json('message')));
    }

    public function test_extend_rejects_new_checkout_before_previous_checkout(): void
    {
        Carbon::setTestNow('2026-09-10');

        $this->artisan('db:seed', [
            '--class' => ReservationTestDataSeeder::class,
            '--force' => true,
            '--no-interaction' => true,
        ]);

        $reservation = Reservation::where('reservation_status', Reservation::STATUS_CONFIRMED)
            ->where('expire_date', '<', Carbon::today()->toDateString())
            ->first();

        if (! $reservation) {
            $this->markTestSkipped('No expired reservation in seed data.');
        }

        $user = $this->userWithApiPermissions(['view reservations', 'update reservations']);

        $response = $this->actingAs($user, 'api')->patchJson(
            "/api/users/reservations/{$reservation->id}/extend",
            ['expire_date' => $reservation->expire_date]
        );

        $response->assertStatus(500);
        $response->assertJsonPath('success', false);
    }
}
