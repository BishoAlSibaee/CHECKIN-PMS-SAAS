<?php

namespace Tests\Feature\Dashboard;

use App\Models\Client;
use App\Models\Reservation;
use App\Models\Reservation_source;
use App\Models\Stay_reason;
use App\Services\RoomOccupancyService;
use Carbon\Carbon;
use Database\Seeders\ReservationTestDataSeeder;
use Tests\TestCase;

class DashboardCheckoutCountsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-08-10');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_check_outs_today_excludes_overdue_in_house_guests(): void
    {
        $this->seedReservationFixtures();
        $today = Carbon::today();
        $service = app(RoomOccupancyService::class);

        $beforeToday = $service->checkOutsToday($today);
        $beforeOverdue = $service->overdueCheckoutsCount($today);
        $beforePending = $service->pendingCheckoutsCount($today);

        $this->createInHouseReservation('2026-08-07', '2026-08-10');
        $this->createInHouseReservation('2026-08-01', '2026-08-05');

        $this->assertSame($beforeToday + 1, $service->checkOutsToday($today));
        $this->assertSame($beforeOverdue + 1, $service->overdueCheckoutsCount($today));
        $this->assertSame($beforePending + 2, $service->pendingCheckoutsCount($today));
        $this->assertCount($beforePending + 2, $service->pendingCheckouts($today));
    }

    public function test_dashboard_summary_and_reservations_pending_checkout_filter_align(): void
    {
        $user = $this->userWithApiPermissions();
        $this->seedReservationFixtures();
        $today = Carbon::today();
        $service = app(RoomOccupancyService::class);

        $beforePending = $service->pendingCheckoutsCount($today);
        $listBefore = $this->actingAs($user, 'api')->getJson('/api/users/reservations?pending_checkout=1');
        $listBefore->assertOk();
        $totalBefore = (int) $listBefore->json('total');

        $this->createInHouseReservation('2026-08-07', '2026-08-10');
        $this->createInHouseReservation('2026-08-01', '2026-08-05');

        $dashboard = $this->actingAs($user, 'api')->getJson('/api/users/dashboard/summary');
        $dashboard->assertOk();

        $this->assertSame(
            $beforePending + 2,
            (int) $dashboard->json('data.pending_checkouts')
        );
        $this->assertCount(
            $beforePending + 2,
            $dashboard->json('data.departures_today')
        );
        $this->assertSame(
            (int) $dashboard->json('data.check_outs_today') + (int) $dashboard->json('data.overdue_checkouts'),
            (int) $dashboard->json('data.pending_checkouts')
        );

        $list = $this->actingAs($user, 'api')->getJson('/api/users/reservations?pending_checkout=1');
        $list->assertOk();
        $this->assertSame($totalBefore + 2, (int) $list->json('total'));
    }

    private function seedReservationFixtures(): void
    {
        $this->artisan('db:seed', [
            '--class' => ReservationTestDataSeeder::class,
            '--force' => true,
            '--no-interaction' => true,
        ]);
    }

    private function createInHouseReservation(string $start, string $expire): Reservation
    {
        $user = $this->userWithOnlyPermissions(['view reservations', 'create reservations']);
        $client = Client::first();
        $stayReason = Stay_reason::first();
        $source = Reservation_source::first();
        $today = Carbon::today()->toDateString();
        $room = $this->createIsolatedTestRoom();
        $this->assertNotNull($room);

        $this->assertNotNull($client);
        $this->assertNotNull($stayReason);
        $this->assertNotNull($source);

        $response = $this->actingAs($user, 'api')->postJson('/api/users/makeReservation', [
            'client_id' => $client->id,
            'rooms' => [['room_id' => $room->id]],
            'start_date' => $today,
            'expire_date' => Carbon::today()->addDays(3)->toDateString(),
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

        $this->assertApiSuccess($response);

        $id = $response->json('data.reservation.id') ?? $response->json('data.id');
        $reservation = Reservation::find($id);
        $this->assertNotNull($reservation);

        $reservation->update([
            'start_date' => $start,
            'expire_date' => $expire,
            'logedin' => Reservation::LOGEDIN_IN_HOUSE,
            'login_time' => $start,
        ]);

        return $reservation->fresh();
    }
}
