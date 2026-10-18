<?php

namespace Tests\Feature\Reservation;

use App\Models\Client;
use App\Models\Reservation;
use App\Models\ReservationPay;
use App\Models\Reservation_source;
use App\Models\Stay_reason;
use Carbon\Carbon;
use Database\Seeders\RefundPolicySeeder;
use Database\Seeders\ReservationTestDataSeeder;
use Tests\TestCase;

class DateChangePreviewTest extends TestCase
{
    use \Illuminate\Foundation\Testing\DatabaseTransactions;

    protected function connectionsToTransact(): array
    {
        return ['mysql', 'mysql2'];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('db:seed', [
            '--class' => RefundPolicySeeder::class,
            '--force' => true,
            '--no-interaction' => true,
        ]);
    }

    public function test_date_change_preview_shows_additional_charge_when_extending_underpaid_stay(): void
    {
        Carbon::setTestNow('2026-06-01');

        $user = $this->userWithApiPermissions(['update reservations']);
        $reservation = $this->createUnderpaidReservation('2026-06-15', '2026-06-22', $user);
        $this->assertNotNull($reservation);

        $response = $this->actingAs($user, 'api')->getJson(
            '/api/users/reservations/'.$reservation->id.'/date-change-preview'
            .'?start_date=2026-06-15&expire_date=2026-06-23'
        );

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.financial_action', 'additional_charge');
        $this->assertGreaterThan(
            (float) $response->json('data.current.balance_due'),
            (float) $response->json('data.projected.balance_due')
        );
        $this->assertGreaterThan(
            (float) $response->json('data.current.total'),
            (float) $response->json('data.projected.total')
        );
        $this->assertEquals(0, (float) $response->json('data.refund_amount'));

        Carbon::setTestNow();
    }

    public function test_date_change_preview_shows_refund_when_overpaid_after_shorten(): void
    {
        Carbon::setTestNow('2026-06-01');

        $user = $this->userWithApiPermissions(['update reservations']);
        $reservation = $this->createPaidReservation('2026-06-15', '2026-06-23', $user);
        $this->assertNotNull($reservation);

        $oldTotal = (float) $reservation->total;

        $response = $this->actingAs($user, 'api')->getJson(
            '/api/users/reservations/'.$reservation->id.'/date-change-preview'
            .'?start_date=2026-06-15&expire_date=2026-06-19'
        );

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.financial_action', 'refund');
        $this->assertLessThan($oldTotal, (float) $response->json('data.projected.total'));
        $this->assertGreaterThan(0, (float) $response->json('data.refund_amount'));

        Carbon::setTestNow();
    }

    private function createPaidReservation(string $startDate, string $endDate, \App\Models\User $user): ?Reservation
    {
        $reservation = $this->createReservation($startDate, $endDate, $user);
        if (! $reservation) {
            return null;
        }

        $pay = $this->actingAs($user, 'api')->postJson(
            "/api/users/reservations/{$reservation->id}/payments",
            ['pay' => (float) $reservation->total, 'type' => ReservationPay::TYPE_PAYMENT]
        );

        return $pay->status() === 200 && $pay->json('success')
            ? $reservation->fresh(['payments'])
            : null;
    }

    private function createUnderpaidReservation(
        string $startDate,
        string $endDate,
        \App\Models\User $user
    ): ?Reservation {
        $reservation = $this->createReservation($startDate, $endDate, $user);
        if (! $reservation) {
            return null;
        }

        $partial = round((float) $reservation->total * 0.8, 2);

        $pay = $this->actingAs($user, 'api')->postJson(
            "/api/users/reservations/{$reservation->id}/payments",
            ['pay' => $partial, 'type' => ReservationPay::TYPE_PAYMENT]
        );

        return $pay->status() === 200 && $pay->json('success')
            ? $reservation->fresh(['payments'])
            : null;
    }

    private function createReservation(string $startDate, string $endDate, \App\Models\User $user): ?Reservation
    {
        $this->artisan('db:seed', [
            '--class' => ReservationTestDataSeeder::class,
            '--force' => true,
            '--no-interaction' => true,
        ]);

        $client = Client::first();
        $stayReason = Stay_reason::first();
        $source = Reservation_source::first();
        if (! $client || ! $stayReason || ! $source) {
            return null;
        }

        $room = $this->findOrCreateAvailableRoom($startDate, $endDate)
            ?? $this->createIsolatedTestRoom();
        if (! $room) {
            return null;
        }

        $create = $this->actingAs($user, 'api')->postJson('/api/users/makeReservation', [
            'client_id' => $client->id,
            'rooms' => [['room_id' => $room->id]],
            'start_date' => $startDate,
            'expire_date' => $endDate,
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

        if ($create->status() !== 200 || ! $create->json('success')) {
            return null;
        }

        $id = $create->json('data.id') ?? $create->json('data.reservation.id');

        return $id ? Reservation::find($id) : Reservation::latest('id')->first();
    }
}
