<?php

namespace Tests\Feature\Clients;

use App\Models\Client;
use App\Models\Reservation;
use App\Models\ReservationPay;
use App\Models\Reservation_source;
use App\Models\Room;
use App\Models\Stay_reason;
use Carbon\Carbon;
use Database\Seeders\ReservationTestDataSeeder;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ClientReservationsApiTest extends TestCase
{
    public function test_reservations_by_client_id_accepts_mysql2_client_and_returns_all_statuses(): void
    {
        Carbon::setTestNow('2026-06-15');

        $this->artisan('db:seed', [
            '--class' => ReservationTestDataSeeder::class,
            '--force' => true,
            '--no-interaction' => true,
        ]);

        $user = $this->userWithApiPermissions();
        $client = Client::first();
        $stayReason = Stay_reason::first();
        $source = Reservation_source::first();
        $start = '2026-06-20';
        $end = '2026-06-23';
        $room = $this->findOrCreateAvailableRoom($start, $end);

        $this->assertNotNull($client);
        $this->assertNotNull($stayReason);
        $this->assertNotNull($source);
        $this->assertNotNull($room);

        $existing = Reservation::where('client_id', $client->id)->first();
        $this->assertNotNull($existing, 'Client must have at least one reservation in test data.');

        $response = $this->actingAs($user, 'api')->getJson('/api/users/reservations/client?client_id='.$client->id);

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $ids = collect($response->json('data'))->pluck('id')->map(fn ($id) => (int) $id);
        $this->assertTrue(
            $ids->contains((int) $existing->id),
            'Client reservations endpoint must return existing bookings.'
        );
        $this->assertGreaterThanOrEqual(1, $response->json('total'));
    }

    public function test_get_client_by_id_includes_reservation_stats(): void
    {
        $user = $this->userWithApiPermissions();
        $client = Client::first();
        $this->assertNotNull($client);

        $countBefore = Reservation::where('client_id', $client->id)->count();

        $response = $this->actingAs($user, 'api')->getJson('/api/users/getClientById/'.$client->id);

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.reservations_count', $countBefore);
    }

    public function test_get_client_by_id_includes_total_spent_net_paid(): void
    {
        $user = $this->userWithApiPermissions();
        $client = Client::first();
        $this->assertNotNull($client);

        $paymentType = ReservationPay::TYPE_PAYMENT;
        $refundType = ReservationPay::TYPE_REFUND;
        $expected = round((float) DB::table('reservation_pay')
            ->join('reservations', 'reservations.id', '=', 'reservation_pay.reservation_id')
            ->where('reservations.client_id', $client->id)
            ->selectRaw(
                'COALESCE(SUM(CASE WHEN reservation_pay.type = ? THEN reservation_pay.pay WHEN reservation_pay.type = ? THEN -reservation_pay.pay ELSE 0 END), 0) as net',
                [$paymentType, $refundType]
            )
            ->value('net'), 2);

        $response = $this->actingAs($user, 'api')->getJson('/api/users/getClientById/'.$client->id);

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $this->assertEquals($expected, (float) $response->json('data.total_spent'));
    }
}
