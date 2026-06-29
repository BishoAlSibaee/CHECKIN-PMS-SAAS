<?php

namespace Tests\Feature\Reservation;

use App\Events\HotelLiveUpdated;
use App\Models\Reservation;
use App\Models\ReservationPay;
use App\Services\HotelLivePublisher;
use Carbon\Carbon;
use Database\Seeders\ReservationTestDataSeeder;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class PaymentBroadcastTest extends TestCase
{
    public function test_payment_dispatches_collections_scope(): void
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

        Event::fake([HotelLiveUpdated::class]);

        $amount = min(100.0, $this->balanceDue($reservation));
        $this->assertGreaterThan(0, $amount);

        $payResponse = $this->actingAs($user, 'api')->postJson(
            "/api/users/reservations/{$reservation->id}/payments",
            ['pay' => $amount, 'type' => ReservationPay::TYPE_PAYMENT]
        );
        $payResponse->assertOk();

        Event::assertDispatched(HotelLiveUpdated::class, function (HotelLiveUpdated $event) use ($reservation) {
            return in_array(HotelLivePublisher::SCOPE_COLLECTIONS, $event->scopes, true)
                && $event->entity === ['type' => 'reservation', 'id' => $reservation->id];
        });

        Carbon::setTestNow();
    }

    private function findReservationWithBalanceDue(string $asOfDate): ?Reservation
    {
        return Reservation::where('reservation_status', 1)
            ->where('expire_date', '>=', $asOfDate)
            ->with('payments')
            ->get()
            ->first(fn (Reservation $r) => $this->balanceDue($r) > 0.005);
    }

    private function createReservationWithBalanceDue(string $asOfDate, \App\Models\User $user): ?Reservation
    {
        $client = \App\Models\Client::first();
        $stayReason = \App\Models\Stay_reason::first();
        $source = \App\Models\Reservation_source::first();
        if (!$client || !$stayReason || !$source) {
            return null;
        }

        $start = Carbon::parse($asOfDate)->addDays(5)->toDateString();
        $end = Carbon::parse($start)->addDays(4)->toDateString();
        $room = $this->findOrCreateAvailableRoom($start, $end);
        if (!$room) {
            return null;
        }

        $response = $this->actingAs($user, 'api')->postJson('/api/users/makeReservation', [
            'client_id'              => $client->id,
            'rooms'                  => [['room_id' => $room->id]],
            'start_date'             => $start,
            'expire_date'            => $end,
            'reservation_type'       => 0,
            'reservation_status'     => 1,
            'stay_reason_id'         => $stayReason->id,
            'reservation_source_id'  => $source->id,
            'rent_type'              => 0,
            'price_calculation_mode' => 0,
            'discount'               => 0,
            'extras'                 => 0,
            'penalties'              => 0,
        ]);

        if ($response->status() !== 200 || !$response->json('success')) {
            $this->fail('Could not create reservation: '.$response->getContent());
        }

        $id = $response->json('data.id') ?? $response->json('data.reservation.id') ?? null;

        return $id ? Reservation::with('payments')->find($id) : Reservation::with('payments')->latest('id')->first();
    }

    private function balanceDue(Reservation $reservation): float
    {
        $paid = (float) $reservation->payments
            ->where('type', ReservationPay::TYPE_PAYMENT)
            ->sum('pay');
        $refunded = (float) $reservation->payments
            ->where('type', ReservationPay::TYPE_REFUND)
            ->sum('pay');

        return max(0, (float) $reservation->total - ($paid - $refunded));
    }
}
