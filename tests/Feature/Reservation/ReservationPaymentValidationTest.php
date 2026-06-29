<?php

namespace Tests\Feature\Reservation;

use App\Models\Client;
use App\Models\Reservation;
use App\Models\Reservation_source;
use App\Models\ReservationRoom;
use App\Models\Room;
use App\Models\Stay_reason;
use Carbon\Carbon;
use Database\Seeders\ReservationTestDataSeeder;
use Tests\TestCase;

class ReservationPaymentValidationTest extends TestCase
{
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

    public function test_make_reservation_rejects_pay_amount_above_total(): void
    {
        $this->artisan('db:seed', [
            '--class' => ReservationTestDataSeeder::class,
            '--force' => true,
            '--no-interaction' => true,
        ]);

        $user = $this->userWithApiPermissions();
        $payload = $this->availableRoomPayload('2026-10-01', '2026-10-04');
        $this->assertNotNull($payload, 'No free room for test dates');

        $response = $this->actingAs($user, 'api')->postJson('/api/users/makeReservation', array_merge($payload, [
            'pay_amount' => 999999.99,
        ]));

        $response->assertStatus(422);
        $response->assertJsonPath('success', false);
        $this->assertStringContainsString('exceeds', strtolower((string) $response->json('message')));
    }

    public function test_add_payment_rejects_amount_above_balance_due(): void
    {
        $this->artisan('db:seed', [
            '--class' => ReservationTestDataSeeder::class,
            '--force' => true,
            '--no-interaction' => true,
        ]);

        $user = $this->userWithApiPermissions();
        $payload = $this->availableRoomPayload('2026-12-01', '2026-12-04');
        $this->assertNotNull($payload, 'No free room for test dates');

        $create = $this->actingAs($user, 'api')->postJson('/api/users/makeReservation', array_merge($payload, [
            'pay_amount' => 0,
        ]));
        $create->assertStatus(200);

        $reservationId = $create->json('data.reservation.id') ?? $create->json('data.id');
        $this->assertNotNull($reservationId);

        $reservation = Reservation::with('payments')->findOrFail($reservationId);
        $balanceDue = $reservation->balanceDue();
        $this->assertGreaterThan(0, $balanceDue);

        $response = $this->actingAs($user, 'api')->postJson(
            "/api/users/reservations/{$reservationId}/payments",
            ['pay' => round($balanceDue + 50, 2), 'type' => 0]
        );

        $response->assertStatus(422);
        $response->assertJsonPath('success', false);
        $this->assertStringContainsString('exceeds', strtolower((string) $response->json('message')));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function availableRoomPayload(string $start, string $end): ?array
    {
        $client = Client::first();
        $stayReason = Stay_reason::first();
        $source = Reservation_source::first();
        if (!$client || !$stayReason || !$source) {
            return null;
        }

        $room = $this->findFreeRoom($start, $end);
        if (!$room) {
            return null;
        }

        return [
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
            'logedin' => 0,
        ];
    }

    private function findFreeRoom(string $start, string $end, ?int $excludeRoomId = null): ?Room
    {
        foreach (Room::where('active', 1)->where('roomStatus', 1)->whereHas('roomType')->get() as $candidate) {
            if ($excludeRoomId !== null && (int) $candidate->id === $excludeRoomId) {
                continue;
            }

            $overlap = ReservationRoom::where('room_id', $candidate->id)
                ->whereHas('reservation', function ($query) use ($start, $end) {
                    $query->where('start_date', '<', $end)
                        ->where('expire_date', '>', $start);
                })->exists();

            if (!$overlap) {
                return $candidate;
            }
        }

        return null;
    }
}
