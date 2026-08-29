<?php

namespace Tests\Feature\Broadcast;

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

class HotelLiveUpdatedBroadcastTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('db:seed', [
            '--class' => ReservationTestDataSeeder::class,
            '--force' => true,
            '--no-interaction' => true,
        ]);
    }

    public function test_check_in_dispatches_hotel_live_updated(): void
    {
        Carbon::setTestNow('2026-08-15');
        Event::fake([HotelLiveUpdated::class]);

        $user = $this->userWithOnlyPermissions([
            'view reservations', 'create reservations', 'update reservations',
            'view rooms', 'manage rooms',
        ]);
        $reservationId = $this->createReservationForToday($user);

        $checkIn = $this->actingAs($user, 'api')->patchJson("/api/users/reservations/{$reservationId}", [
            'logedin'    => 1,
            'login_time' => '2026-08-15',
        ]);
        $checkIn->assertOk();

        Event::assertDispatched(HotelLiveUpdated::class, function (HotelLiveUpdated $event) use ($reservationId) {
            return in_array(HotelLivePublisher::SCOPE_RESERVATIONS, $event->scopes, true)
                || in_array(HotelLivePublisher::SCOPE_OCCUPANCY_BOARD, $event->scopes, true);
        });

        Carbon::setTestNow();
    }

    public function test_update_room_dispatches_hotel_live_updated(): void
    {
        Event::fake([HotelLiveUpdated::class]);

        $user = $this->userWithOnlyPermissions(['view rooms', 'manage rooms']);
        $room = Room::where('active', 1)->first();
        $this->assertNotNull($room);

        $response = $this->actingAs($user, 'api')->postJson('/api/users/updateRoom', [
            'id'         => $room->id,
            'roomStatus' => ReservationRoomStatusService::ROOM_OUT_OF_SERVICE,
            'active'     => $room->active,
        ]);
        $response->assertOk();

        Event::assertDispatched(HotelLiveUpdated::class, function (HotelLiveUpdated $event) {
            return in_array(HotelLivePublisher::SCOPE_OCCUPANCY_BOARD, $event->scopes, true);
        });
    }

    private function createReservationForToday(\App\Models\User $user): int
    {
        $client = Client::first();
        $stayReason = Stay_reason::first();
        $source = Reservation_source::first();
        $room = $this->findOrCreateAvailableRoom('2026-08-15', '2026-08-18');
        $this->assertNotNull($room);

        $create = $this->actingAs($user, 'api')->postJson('/api/users/makeReservation', [
            'client_id'              => $client->id,
            'rooms'                  => [['room_id' => $room->id]],
            'start_date'             => '2026-08-15',
            'expire_date'            => '2026-08-18',
            'reservation_type'       => 0,
            'reservation_status'     => 1,
            'stay_reason_id'         => $stayReason->id,
            'reservation_source_id'  => $source->id,
            'rent_type'              => 0,
            'price_calculation_mode' => 0,
            'discount'               => 0,
            'extras'                 => 0,
            'penalties'              => 0,
            'pay_amount'             => 0,
            'logedin'                => 0,
        ]);
        $create->assertOk();

        return (int) $create->json('data.id');
    }
}
