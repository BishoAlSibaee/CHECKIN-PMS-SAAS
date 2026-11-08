<?php

namespace Tests\Feature\Dashboard;

use App\Events\HotelLiveUpdated;
use App\Models\Client;
use App\Models\Reservation;
use App\Models\Reservation_source;
use App\Models\ReservationRoom;
use App\Models\Room;
use App\Models\Stay_reason;
use App\Services\HotelLivePublisher;
use App\Services\ReservationRoomStatusService;
use Carbon\Carbon;
use Database\Seeders\ReservationTestDataSeeder;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class RoomBoardTest extends TestCase
{
    use \Illuminate\Foundation\Testing\DatabaseTransactions;

    protected function connectionsToTransact(): array
    {
        return ['mysql', 'mysql2'];
    }

    public function test_occupancy_board_for_demo_date(): void
    {
        $this->artisan('db:seed', [
            '--class' => ReservationTestDataSeeder::class,
            '--force' => true,
            '--no-interaction' => true,
        ]);

        $user = $this->userWithApiPermissions();

        $response = $this->actingAs($user, 'api')->getJson('/api/users/rooms/occupancy-board?date=2026-08-01');
        $response->assertStatus(200);
        $response->assertJsonPath('success', true);

        $summary = $response->json('data.summary') ?? $response->json('data.data.summary');
        $this->assertNotNull($summary);
        $this->assertGreaterThan(0, $summary['total'] ?? 0);

        $occupied = ($summary['in_house'] ?? 0)
            + ($summary['reserved'] ?? 0)
            + ($summary['check_in_today'] ?? 0)
            + ($summary['check_out_today'] ?? 0);

        $this->assertGreaterThan(0, $occupied);
    }

    public function test_check_in_via_api_dispatches_hotel_live_updated(): void
    {
        Carbon::setTestNow('2026-08-15');

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
        $reservationId = (int) ($create->json('data.reservation.id') ?? $create->json('data.id'));

        $checkIn = $this->actingAs($user, 'api')->patchJson("/api/users/reservations/{$reservationId}", [
            'logedin'    => 1,
            'login_time' => '2026-08-15',
        ]);
        $checkIn->assertOk();

        Event::assertDispatched(HotelLiveUpdated::class, function (HotelLiveUpdated $event) {
            return in_array(HotelLivePublisher::SCOPE_OCCUPANCY_BOARD, $event->scopes, true)
                || $event->action === 'checked_in';
        });

        Carbon::setTestNow();
    }

    /**
     * Regression: a room checked out individually (another room on the same
     * reservation still in-house) must show as its own status on the board,
     * not "in_house" (reservation's aggregate) and not "check_in_today"
     * (mistaken for a pending arrival just because the stay starts today).
     */
    public function test_board_reflects_a_single_rooms_checkout_independent_of_its_roommate(): void
    {
        Carbon::setTestNow('2026-09-20');

        $this->artisan('db:seed', [
            '--class' => ReservationTestDataSeeder::class,
            '--force' => true,
            '--no-interaction' => true,
        ]);

        $user = $this->userWithApiPermissions(['view reservations', 'create reservations', 'update reservations']);
        $client = Client::first();
        $stayReason = Stay_reason::first();
        $source = Reservation_source::first();
        $roomA = $this->findOrCreateAvailableRoom('2026-09-20', '2026-09-22');
        $roomB = $this->createIsolatedTestRoom();
        $this->assertNotNull($roomA);
        $this->assertNotNull($roomB);

        $create = $this->actingAs($user, 'api')->postJson('/api/users/makeReservation', [
            'client_id' => $client->id,
            'rooms' => [['room_id' => $roomA->id], ['room_id' => $roomB->id]],
            'start_date' => '2026-09-20',
            'expire_date' => '2026-09-22',
            'reservation_type' => 0,
            'reservation_status' => Reservation::STATUS_CONFIRMED,
            'stay_reason_id' => $stayReason->id,
            'reservation_source_id' => $source->id,
            'rent_type' => 0,
            'price_calculation_mode' => 0,
            'logedin' => Reservation::LOGEDIN_IN_HOUSE,
        ]);
        $create->assertOk();
        $reservationId = (int) $create->json('data.reservation.id');

        $reservation = Reservation::with('reservationRooms')->findOrFail($reservationId);
        $resRoomA = $reservation->reservationRooms->firstWhere('room_id', $roomA->id);

        $this->actingAs($user, 'api')
            ->postJson("/api/users/reservations/{$reservationId}/rooms/{$resRoomA->id}/check-out")
            ->assertOk();

        $board = $this->actingAs($user, 'api')->getJson('/api/users/rooms/occupancy-board?date=2026-09-20');
        $board->assertOk();

        $rooms = collect($board->json('data.rooms'));
        $boardRoomA = $rooms->firstWhere('id', $roomA->id);
        $boardRoomB = $rooms->firstWhere('id', $roomB->id);

        $this->assertNotNull($boardRoomA);
        $this->assertNotNull($boardRoomB);
        $this->assertNotSame('in_house', $boardRoomA['occupancy_status']);
        $this->assertNotSame('check_in_today', $boardRoomA['occupancy_status']);
        $this->assertSame('in_house', $boardRoomB['occupancy_status']);

        Carbon::setTestNow();
    }
}
