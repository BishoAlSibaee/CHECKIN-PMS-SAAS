<?php

namespace Tests\Feature\Reservation;

use App\Events\HotelLiveUpdated;
use App\Models\Client;
use App\Models\Reservation_source;
use App\Models\Stay_reason;
use App\Services\HotelLivePublisher;
use Carbon\Carbon;
use Database\Seeders\ReservationTestDataSeeder;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class CreateReservationBroadcastTest extends TestCase
{
    public function test_create_reservation_dispatches_reservations_and_board_scopes(): void
    {
        Carbon::setTestNow('2026-08-20');

        $this->artisan('db:seed', [
            '--class' => ReservationTestDataSeeder::class,
            '--force' => true,
            '--no-interaction' => true,
        ]);

        Event::fake([HotelLiveUpdated::class]);

        $user = $this->userWithApiPermissions();
        $client = Client::first();
        $stayReason = Stay_reason::first();
        $source = Reservation_source::first();
        $room = $this->findOrCreateAvailableRoom('2026-08-20', '2026-08-23');
        $this->assertNotNull($room);

        $create = $this->actingAs($user, 'api')->postJson('/api/users/makeReservation', [
            'client_id'              => $client->id,
            'rooms'                  => [['room_id' => $room->id]],
            'start_date'             => '2026-08-20',
            'expire_date'            => '2026-08-23',
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

        Event::assertDispatched(HotelLiveUpdated::class, function (HotelLiveUpdated $event) {
            return in_array(HotelLivePublisher::SCOPE_RESERVATIONS, $event->scopes, true)
                && in_array(HotelLivePublisher::SCOPE_OCCUPANCY_BOARD, $event->scopes, true)
                && $event->action === 'reservation_created';
        });

        Carbon::setTestNow();
    }
}
