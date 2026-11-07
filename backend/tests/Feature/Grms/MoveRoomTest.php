<?php

namespace Tests\Feature\Grms;

use App\Models\Client;
use App\Models\Reservation;
use App\Models\ReservationDailyCharge;
use App\Models\Reservation_source;
use App\Models\ReservationRoom;
use App\Models\Room;
use App\Models\Stay_reason;
use Carbon\Carbon;
use Tests\TestCase;

class MoveRoomTest extends TestCase
{
    use \Illuminate\Foundation\Testing\DatabaseTransactions;

    protected function connectionsToTransact(): array
    {
        return ['mysql', 'mysql2'];
    }

    private function makeInHouseReservation(Room $room, string $start, string $end): array
    {
        $this->artisan('db:seed', [
            '--class' => \Database\Seeders\EssentialReferenceDataSeeder::class,
            '--force' => true,
            '--no-interaction' => true,
        ]);

        $client = Client::first() ?? Client::create([
            'first_name' => 'Guest',
            'last_name' => 'Move'.$this->uniqueSuffix(),
            'international_code' => '+966',
            'mobile' => '05'.substr($this->uniqueSuffix(), -8),
            'IdType' => 'PASSPORT',
            'IdNumber' => 'TEST-'.$this->uniqueSuffix(),
            'gender' => 'MALE',
            'guest_type' => 'VISITOR',
        ]);
        $stayReason = Stay_reason::first();
        $source = Reservation_source::first();

        $reservation = Reservation::query()->create([
            'client_id' => $client->id,
            'start_date' => $start,
            'expire_date' => $end,
            'nights' => Carbon::parse($start)->diffInDays(Carbon::parse($end)),
            'reservation_type' => 0,
            'reservation_status' => Reservation::STATUS_CONFIRMED,
            'stay_reason_id' => $stayReason->id,
            'reservation_source_id' => $source->id,
            'rent_type' => 0,
            'price_calculation_mode' => 0,
            'base_price' => 500,
            'classification_discount' => 0,
            'discount' => 0,
            'extras' => 0,
            'penalties' => 0,
            'subtotal' => 500,
            'taxes' => 75,
            'total' => 575,
            'logedin' => Reservation::LOGEDIN_IN_HOUSE,
            'login_time' => $start,
            'user_id' => $this->apiTestUser()->id,
        ]);

        $resRoom = ReservationRoom::query()->create([
            'reservation_id' => $reservation->id,
            'room_id' => $room->id,
            'price' => 575,
        ]);

        // Simulate daily charges already persisted for the original stay/room,
        // as a real makeReservation() call would have done.
        foreach (range(0, Carbon::parse($start)->diffInDays(Carbon::parse($end)) - 1) as $i) {
            ReservationDailyCharge::create([
                'reservation_id' => $reservation->id,
                'reservation_room_id' => $resRoom->id,
                'room_id' => $room->id,
                'charge_date' => Carbon::parse($start)->addDays($i)->toDateString(),
                'base_amount' => 250,
                'is_peak_day' => false,
                'is_in_plan' => false,
                'price_source' => 'min',
                'rent_type' => 0,
            ]);
        }

        return [$reservation, $resRoom];
    }

    public function test_moving_a_room_keeps_past_nights_and_repoints_future_nights(): void
    {
        Carbon::setTestNow('2026-08-16');

        $oldRoom = $this->findOrCreateAvailableRoom('2026-08-15', '2026-08-18');
        $this->assertNotNull($oldRoom);
        [$reservation, $resRoom] = $this->makeInHouseReservation($oldRoom, '2026-08-15', '2026-08-18');

        $newRoom = $this->findOrCreateAvailableRoom('2026-08-16', '2026-08-18');
        $this->assertNotNull($newRoom);
        $this->assertNotSame($oldRoom->id, $newRoom->id);

        $user = $this->userWithApiPermissions(['view reservations', 'update reservations']);
        $response = $this->actingAs($user, 'api')->postJson(
            "/api/users/reservations/{$reservation->id}/rooms/{$resRoom->id}/move",
            ['new_room_id' => $newRoom->id, 'reprice' => false],
        );

        $response->assertOk();
        $response->assertJsonPath('success', true);

        // Past night (before the move date) keeps the OLD room untouched.
        $this->assertDatabaseHas('reservation_daily_charges', [
            'reservation_room_id' => $resRoom->id,
            'charge_date' => '2026-08-15',
            'room_id' => $oldRoom->id,
            'base_amount' => 250,
        ]);

        // Future nights (from the move date) are now on the NEW room.
        $this->assertDatabaseHas('reservation_daily_charges', [
            'reservation_room_id' => $resRoom->id,
            'charge_date' => '2026-08-16',
            'room_id' => $newRoom->id,
        ]);
        $this->assertDatabaseHas('reservation_daily_charges', [
            'reservation_room_id' => $resRoom->id,
            'charge_date' => '2026-08-17',
            'room_id' => $newRoom->id,
        ]);

        $this->assertSame($newRoom->id, $resRoom->fresh()->room_id);

        Carbon::setTestNow();
    }

    public function test_moving_a_room_to_an_occupied_room_is_rejected(): void
    {
        Carbon::setTestNow('2026-08-16');

        $oldRoom = $this->findOrCreateAvailableRoom('2026-08-15', '2026-08-18');
        [$reservation, $resRoom] = $this->makeInHouseReservation($oldRoom, '2026-08-15', '2026-08-18');

        $busyRoom = $this->findOrCreateAvailableRoom('2026-08-15', '2026-08-18');
        [, $busyResRoom] = $this->makeInHouseReservation($busyRoom, '2026-08-15', '2026-08-18');

        $user = $this->userWithApiPermissions(['view reservations', 'update reservations']);
        $response = $this->actingAs($user, 'api')->postJson(
            "/api/users/reservations/{$reservation->id}/rooms/{$resRoom->id}/move",
            ['new_room_id' => $busyRoom->id],
        );

        $response->assertStatus(500);
        $response->assertJsonPath('success', false);

        Carbon::setTestNow();
    }
}
