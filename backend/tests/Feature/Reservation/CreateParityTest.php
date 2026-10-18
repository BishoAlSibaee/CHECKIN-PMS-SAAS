<?php

namespace Tests\Feature\Reservation;

use App\Models\Client;
use App\Models\Reservation;
use App\Models\ReservationDailyCharge;
use App\Models\ReservationRoom;
use App\Models\Reservation_source;
use App\Models\Room;
use App\Models\Stay_reason;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\ReservationTestDataSeeder;
use Tests\TestCase;

class CreateParityTest extends TestCase
{
    use \Illuminate\Foundation\Testing\DatabaseTransactions;

    protected function connectionsToTransact(): array
    {
        return ['mysql', 'mysql2'];
    }

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-04-01');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_make_reservation_base_price_equals_daily_charges(): void
    {
        $this->artisan('db:seed', [
            '--class' => ReservationTestDataSeeder::class,
            '--force' => true,
            '--no-interaction' => true,
        ]);

        $user = $this->userWithApiPermissions();

        $client = Client::first();
        $stayReason = Stay_reason::first();
        $source = Reservation_source::first();

        $this->assertNotNull($client);
        $this->assertNotNull($stayReason);
        $this->assertNotNull($source);

        $start = '2026-10-01';
        $end = '2026-10-08';

        $roomStatusService = app(\App\Services\ReservationRoomStatusService::class);

        $room = null;
        foreach (Room::where('active', 1)->where('roomStatus', 1)->whereHas('roomType')->get() as $candidate) {
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
                $room = $candidate;
                break;
            }
        }

        $this->assertNotNull($room, 'No free room for test dates');

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

        $body = $response->json();
        $this->assertTrue($response->status() === 200 && ($body['success'] ?? false), $body['message'] ?? $response->getContent());

        $reservationId = $body['data']['id'] ?? $body['data']['reservation']['id'] ?? null;
        $this->assertNotNull($reservationId);

        $reservation = Reservation::find($reservationId);
        $sum = (float) ReservationDailyCharge::where('reservation_id', $reservation->id)->sum('base_amount');

        $this->assertEqualsWithDelta((float) $reservation->base_price, $sum, 0.02);
    }
}
