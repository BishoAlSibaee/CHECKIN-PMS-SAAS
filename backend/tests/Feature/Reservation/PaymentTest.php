<?php

namespace Tests\Feature\Reservation;

use App\Models\Reservation;
use App\Models\ReservationPay;
use Carbon\Carbon;
use Database\Seeders\ReservationTestDataSeeder;
use Tests\TestCase;

class PaymentTest extends TestCase
{
    public function test_payment_appears_in_earnings_summary(): void
    {
        $this->artisan('db:seed', [
            '--class' => ReservationTestDataSeeder::class,
            '--force' => true,
            '--no-interaction' => true,
        ]);

        $user = $this->userWithApiPermissions();
        $payDate = '2026-08-15';
        Carbon::setTestNow($payDate);

        $reservation = $this->findReservationWithBalanceDue($payDate);
        if (!$reservation) {
            $reservation = $this->createReservationWithBalanceDue($payDate, $user);
        }
        $this->assertNotNull($reservation, 'No reservation with remaining balance for test date.');

        $paid = (float) $reservation->payments()->where('type', ReservationPay::TYPE_PAYMENT)->sum('pay');
        $refunded = (float) $reservation->payments()->where('type', ReservationPay::TYPE_REFUND)->sum('pay');
        $balanceDue = max(0, (float) $reservation->total - ($paid - $refunded));
        $amount = min(250.50, $balanceDue);
        $this->assertGreaterThan(0, $amount, 'Reservation has no payable balance.');

        $before = $this->actingAs($user, 'api')->getJson(
            '/api/users/earnings-summary?start_date=2026-08-01&end_date=2026-08-31'
        );
        $before->assertStatus(200);
        $totalBefore = (float) ($before->json('data.total_in') ?? 0);

        $payResponse = $this->actingAs($user, 'api')->postJson(
            "/api/users/reservations/{$reservation->id}/payments",
            ['pay' => $amount, 'type' => ReservationPay::TYPE_PAYMENT]
        );
        $payResponse->assertStatus(200);
        $payResponse->assertJsonPath('success', true);

        $this->assertDatabaseHas('reservation_pay', [
            'reservation_id' => $reservation->id,
            'pay' => $amount,
        ]);

        $after = $this->actingAs($user, 'api')->getJson(
            '/api/users/earnings-summary?start_date=2026-08-01&end_date=2026-08-31'
        );
        $after->assertStatus(200);

        $totalAfter = (float) ($after->json('data.total_in') ?? 0);
        $this->assertEqualsWithDelta($totalBefore + $amount, $totalAfter, 0.02);

        Carbon::setTestNow();
    }

    private function findReservationWithBalanceDue(string $asOfDate): ?Reservation
    {
        $candidates = Reservation::where('reservation_status', 1)
            ->where('expire_date', '>=', $asOfDate)
            ->with('payments')
            ->get();

        foreach ($candidates as $reservation) {
            $paid = (float) $reservation->payments
                ->where('type', ReservationPay::TYPE_PAYMENT)
                ->sum('pay');
            $refunded = (float) $reservation->payments
                ->where('type', ReservationPay::TYPE_REFUND)
                ->sum('pay');
            $balanceDue = max(0, (float) $reservation->total - ($paid - $refunded));

            if ($balanceDue > 0.01) {
                return $reservation;
            }
        }

        return null;
    }

    private function createReservationWithBalanceDue(string $asOfDate, \App\Models\User $user): ?Reservation
    {
        $client = \App\Models\Client::first();
        $stayReason = \App\Models\Stay_reason::first();
        $source = \App\Models\Reservation_source::first();
        if (!$client || !$stayReason || !$source) {
            return null;
        }

        $start = \Carbon\Carbon::parse($asOfDate)->addDays(5)->toDateString();
        $end = \Carbon\Carbon::parse($start)->addDays(4)->toDateString();

        $room = $this->findOrCreateAvailableRoom($start, $end);
        if (!$room) {
            return null;
        }

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
        ]);

        if ($response->status() !== 200 || !$response->json('success')) {
            return null;
        }

        $id = $response->json('data.id') ?? $response->json('data.reservation.id') ?? null;

        return $id ? Reservation::with('payments')->find($id) : Reservation::with('payments')->latest('id')->first();
    }
}
