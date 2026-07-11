<?php

namespace Tests\Feature\Reservation;

use App\Models\Client;
use App\Models\JournalEntry;
use App\Models\Reservation;
use App\Models\Reservation_source;
use App\Models\ReservationPay;
use App\Models\ReservationRoom;
use App\Models\Room;
use App\Models\Stay_reason;
use Carbon\Carbon;
use Database\Seeders\ChartOfAccountsSeeder;
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

    public function test_initial_payment_on_make_reservation_posts_accounting_journal(): void
    {
        $this->artisan('db:seed', [
            '--class' => ReservationTestDataSeeder::class,
            '--force' => true,
            '--no-interaction' => true,
        ]);
        $this->artisan('db:seed', [
            '--class' => ChartOfAccountsSeeder::class,
            '--force' => true,
            '--no-interaction' => true,
        ]);

        $user = $this->userWithApiPermissions();
        $payload = $this->availableRoomPayload('2026-12-10', '2026-12-13');
        $this->assertNotNull($payload, 'No free room for test dates');

        $response = $this->actingAs($user, 'api')->postJson('/api/users/makeReservation', array_merge($payload, [
            'pay_amount' => 100,
        ]));

        if ($response->status() === 500) {
            $this->fail('makeReservation 500: '.($response->json('message') ?? $response->getContent()));
        }

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);

        $reservationId = $response->json('data.reservation.id') ?? $response->json('data.id');
        $this->assertNotNull($reservationId);

        $payment = ReservationPay::query()
            ->where('reservation_id', $reservationId)
            ->where('pay', 100)
            ->first();

        $this->assertNotNull($payment);

        $entry = JournalEntry::query()
            ->where('source_type', 'reservation_pay')
            ->where('source_id', $payment->id)
            ->first();

        $this->assertNotNull($entry, 'Initial reservation payment must be posted to accounting journal.');
        $this->assertDatabaseHas('journal_entry_lines', [
            'journal_entry_id' => $entry->id,
            'reservation_id' => $reservationId,
            'debit' => 100,
            'credit' => 0,
        ]);
        $this->assertDatabaseHas('journal_entry_lines', [
            'journal_entry_id' => $entry->id,
            'reservation_id' => $reservationId,
            'debit' => 0,
            'credit' => 100,
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function availableRoomPayload(string $start, string $end): ?array
    {
        $client = Client::first();
        $stayReason = Stay_reason::first();
        $source = Reservation_source::first();
        if (! $client || ! $stayReason || ! $source) {
            return null;
        }

        $room = $this->createIsolatedTestRoom() ?? $this->findOrCreateAvailableRoom($start, $end);
        if (! $room) {
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

            if (! $overlap) {
                return $candidate;
            }
        }

        return null;
    }
}
