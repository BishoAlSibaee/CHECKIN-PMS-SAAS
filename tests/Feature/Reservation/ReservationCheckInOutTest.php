<?php

namespace Tests\Feature\Reservation;

use App\Events\HotelLiveUpdated;
use App\Models\Client;
use App\Models\Reservation_source;
use App\Models\Room;
use App\Models\Stay_reason;
use App\Services\HotelLivePublisher;
use App\Services\ReservationRoomStatusService;
use Carbon\Carbon;
use Database\Seeders\ReservationTestDataSeeder;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class ReservationCheckInOutTest extends TestCase
{
    public function test_check_in_and_check_out_updates_logedin(): void
    {
        Carbon::setTestNow('2026-08-15');

        $this->artisan('db:seed', [
            '--class' => ReservationTestDataSeeder::class,
            '--force' => true,
            '--no-interaction' => true,
        ]);

        $user = $this->userWithApiPermissions(['view reservations', 'update reservations']);
        $client = Client::first();
        $stayReason = Stay_reason::first();
        $source = Reservation_source::first();
        $room = $this->findOrCreateAvailableRoom('2026-08-15', '2026-08-18');
        $this->assertNotNull($room);

        $this->assertNotNull($client);
        $this->assertNotNull($stayReason);
        $this->assertNotNull($source);
        $this->assertNotNull($room);

        $room = $this->findOrCreateAvailableRoom('2026-08-15', '2026-08-18');
        $this->assertNotNull($room);

        $create = $this->actingAs($user, 'api')->postJson('/api/users/makeReservation', [
            'client_id' => $client->id,
            'rooms' => [['room_id' => $room->id]],
            'start_date' => '2026-08-15',
            'expire_date' => '2026-08-18',
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
        $create->assertOk();
        $create->assertJsonPath('success', true);

        $reservationId = $create->json('data.reservation.id') ?? $create->json('data.id');
        $this->assertNotNull($reservationId);

        Event::fake([HotelLiveUpdated::class]);

        Room::where('id', $room->id)->update(['roomStatus' => ReservationRoomStatusService::ROOM_PREPARATION]);

        $blocked = $this->actingAs($user, 'api')->patchJson("/api/users/reservations/{$reservationId}", [
            'logedin' => 1,
            'login_time' => '2026-08-15',
        ]);
        $blocked->assertJsonPath('success', false);
        $this->assertDatabaseHas('reservations', [
            'id' => $reservationId,
            'logedin' => 0,
        ]);

        $showAfterBlock = $this->actingAs($user, 'api')->getJson("/api/users/reservations/{$reservationId}");
        $showAfterBlock->assertOk();
        $showAfterBlock->assertJsonPath('data.reservation.id', $reservationId);

        Room::where('id', $room->id)->update(['roomStatus' => ReservationRoomStatusService::ROOM_AVAILABLE]);

        $checkIn = $this->actingAs($user, 'api')->patchJson("/api/users/reservations/{$reservationId}", [
            'logedin' => 1,
            'login_time' => '2026-08-15',
        ]);
        $checkIn->assertOk();
        $checkIn->assertJsonPath('success', true);
        $this->assertDatabaseHas('reservations', [
            'id' => $reservationId,
            'logedin' => 1,
        ]);

        Event::assertDispatched(HotelLiveUpdated::class, function (HotelLiveUpdated $event) {
            return $event->action === 'checked_in'
                || in_array(HotelLivePublisher::SCOPE_OCCUPANCY_BOARD, $event->scopes, true);
        });

        Event::fake([HotelLiveUpdated::class]);

        $reservation = \App\Models\Reservation::with('payments')->findOrFail($reservationId);
        $balance = $reservation->balanceDue();
        if ($balance > 0.005) {
            $pay = $this->actingAs($user, 'api')->postJson("/api/users/reservations/{$reservationId}/payments", [
                'pay' => round($balance, 2),
                'type' => \App\Models\ReservationPay::TYPE_PAYMENT,
            ]);
            $pay->assertOk();
            $pay->assertJsonPath('success', true);
        }

        Carbon::setTestNow('2026-08-18');

        $checkOut = $this->actingAs($user, 'api')->patchJson("/api/users/reservations/{$reservationId}", [
            'logedin' => 0,
        ]);
        $checkOut->assertOk();
        $checkOut->assertJsonPath('success', true);
        $this->assertDatabaseHas('reservations', [
            'id' => $reservationId,
            'logedin' => 0,
        ]);

        Event::assertDispatched(HotelLiveUpdated::class, function (HotelLiveUpdated $event) {
            return $event->action === 'checked_out'
                || in_array(HotelLivePublisher::SCOPE_OCCUPANCY_BOARD, $event->scopes, true);
        });

        Carbon::setTestNow();
    }
}
