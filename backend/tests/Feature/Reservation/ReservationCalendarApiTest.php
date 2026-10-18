<?php

namespace Tests\Feature\Reservation;

use App\Models\Reservation;
use App\Models\ReservationPay;
use Carbon\Carbon;
use Database\Seeders\ReservationTestDataSeeder;
use Tests\TestCase;

class ReservationCalendarApiTest extends TestCase
{
    use \Illuminate\Foundation\Testing\DatabaseTransactions;

    protected function connectionsToTransact(): array
    {
        return ['mysql', 'mysql2'];
    }

    public function test_calendar_event_end_matches_expire_date_not_next_day(): void
    {
        Carbon::setTestNow('2026-06-20');

        $this->artisan('db:seed', [
            '--class' => ReservationTestDataSeeder::class,
            '--force' => true,
            '--no-interaction' => true,
        ]);

        $user = $this->userWithApiPermissions();

        $reservation = Reservation::query()
            ->where('start_date', '2026-06-12')
            ->where('expire_date', '2026-06-20')
            ->first();

        $this->assertNotNull($reservation, 'Expected seeded reservation 12→20 Jun');

        $calendar = $this->actingAs($user, 'api')->getJson(
            '/api/users/reservations/calendar?date_from=2026-06-01&date_to=2026-06-30'
        );

        $calendar->assertOk();
        $event = collect($calendar->json('data.events'))->firstWhere('id', $reservation->id);
        $this->assertNotNull($event);
        $this->assertSame('2026-06-12', $event['start']);
        $this->assertSame('2026-06-20', $event['end'], 'Calendar API end must equal expire_date (checkout day)');

        $list = $this->actingAs($user, 'api')->getJson(
            '/api/users/reservations?date_from=2026-06-01&date_to=2026-06-30&perPage=50'
        );
        $list->assertOk();
        $row = collect($list->json('data'))->firstWhere('id', $reservation->id);
        $this->assertNotNull($row);
        $this->assertSame('2026-06-20', $row['expire_date']);
        $this->assertSame($row['expire_date'], $event['end'], 'List checkout and calendar end must match');

        Carbon::setTestNow();
    }

    public function test_checked_in_reservation_with_balance_due_uses_payment_calendar_state(): void
    {
        Carbon::setTestNow('2026-06-20');

        $this->artisan('db:seed', [
            '--class' => ReservationTestDataSeeder::class,
            '--force' => true,
            '--no-interaction' => true,
        ]);

        $client = \App\Models\Client::first();
        $stayReason = \App\Models\Stay_reason::first();
        $source = \App\Models\Reservation_source::first();
        $user = $this->apiTestUser();

        $this->assertNotNull($client);
        $this->assertNotNull($stayReason);
        $this->assertNotNull($source);

        $reservation = Reservation::query()->create([
            'client_id' => $client->id,
            'start_date' => '2026-06-18',
            'expire_date' => '2026-06-24',
            'nights' => 6,
            'reservation_type' => 0,
            'reservation_status' => Reservation::STATUS_CONFIRMED,
            'stay_reason_id' => $stayReason->id,
            'reservation_source_id' => $source->id,
            'rent_type' => 0,
            'base_price' => 900,
            'discount' => 0,
            'extras' => 0,
            'penalties' => 0,
            'subtotal' => 900,
            'taxes' => 100,
            'total' => 1000,
            'logedin' => Reservation::LOGEDIN_IN_HOUSE,
            'login_time' => now(),
            'user_id' => $user->id,
        ]);

        ReservationPay::query()->create([
            'reservation_id' => $reservation->id,
            'pay' => 250,
            'type' => ReservationPay::TYPE_PAYMENT,
            'user_id' => $user->id,
        ]);

        $calendar = $this->actingAs($this->userWithApiPermissions(), 'api')->getJson(
            '/api/users/reservations/calendar?date_from=2026-06-01&date_to=2026-06-30'
        );

        $calendar->assertOk();
        $event = collect($calendar->json('data.events'))->firstWhere('id', $reservation->id);
        $this->assertNotNull($event);
        $this->assertSame(750.0, (float) $event['balance_due']);
        $this->assertSame('partial-pay', $event['calendar_state']);

        Carbon::setTestNow();
    }
}
