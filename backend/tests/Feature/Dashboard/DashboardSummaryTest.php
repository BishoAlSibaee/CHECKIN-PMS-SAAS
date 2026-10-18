<?php

namespace Tests\Feature\Dashboard;

use App\Services\RevenueAccrualService;
use App\Services\RoomOccupancyService;
use Carbon\Carbon;
use Database\Seeders\ReservationTestDataSeeder;
use Tests\TestCase;

class DashboardSummaryTest extends TestCase
{
    use \Illuminate\Foundation\Testing\DatabaseTransactions;

    protected function connectionsToTransact(): array
    {
        return ['mysql', 'mysql2'];
    }

    private function seedAndUser()
    {
        $this->artisan('db:seed', [
            '--class' => ReservationTestDataSeeder::class,
            '--force' => true,
            '--no-interaction' => true,
        ]);

        return $this->userWithApiPermissions(['view reservations', 'view revenue', 'view earnings']);
    }

    public function test_dashboard_occupancy_matches_room_board_for_today(): void
    {
        $user = $this->seedAndUser();
        $today = Carbon::today()->toDateString();

        $dashboard = $this->actingAs($user, 'api')->getJson('/api/users/dashboard/summary');
        $board = $this->actingAs($user, 'api')->getJson("/api/users/rooms/occupancy-board?date={$today}");

        $dashboard->assertOk();
        $board->assertOk();

        $dashOcc = $dashboard->json('data.occupancy');
        $boardOcc = $board->json('data.summary');

        $this->assertSame((int) ($boardOcc['total'] ?? 0), (int) ($dashOcc['total'] ?? 0));
        $this->assertSame(
            round((float) ($boardOcc['occupancy_rate'] ?? 0), 1),
            round((float) ($dashOcc['occupancy_rate'] ?? 0), 1)
        );
        $this->assertSame((int) ($boardOcc['in_house'] ?? 0), (int) ($dashOcc['in_house'] ?? 0));
    }

    public function test_dashboard_avg_daily_rate_uses_room_nights_for_last_30_days(): void
    {
        $user = $this->seedAndUser();
        $today = Carbon::today();
        $periodStart = $today->copy()->subDays(29)->startOfDay();
        $periodEnd = $today->copy()->endOfDay();

        $expected = app(RevenueAccrualService::class)->calculate(
            'total',
            null,
            $periodStart,
            $periodEnd,
            false
        );

        $roomNights = (int) ($expected['current']['count'] ?? 0);
        $base = (float) ($expected['current']['total_base'] ?? 0);
        $expectedAdr = $roomNights > 0 ? round($base / $roomNights, 2) : 0.0;

        $response = $this->actingAs($user, 'api')->getJson('/api/users/dashboard/summary');
        $response->assertOk();

        $this->assertSame($roomNights, (int) $response->json('data.month_room_nights'));
        $this->assertEqualsWithDelta($expectedAdr, (float) $response->json('data.avg_daily_rate'), 0.05);
        $this->assertSame($periodStart->toDateString(), $response->json('data.accrual_period_start'));
        $this->assertSame($periodEnd->toDateString(), $response->json('data.accrual_period_end'));
    }

    public function test_weekly_accrual_matches_service_per_day(): void
    {
        $user = $this->seedAndUser();
        $today = Carbon::today();
        $service = app(RevenueAccrualService::class);

        $response = $this->actingAs($user, 'api')->getJson('/api/users/dashboard/summary');
        $response->assertOk();

        $weekly = $response->json('data.weekly_accrual');
        $this->assertCount(7, $weekly['labels'] ?? []);
        $this->assertCount(7, $weekly['values'] ?? []);

        foreach ($weekly['labels'] as $index => $isoDate) {
            $day = Carbon::parse($isoDate);
            $expected = $service->calculate('total', null, $day, $day, false);
            $this->assertEqualsWithDelta(
                round((float) ($expected['current']['total'] ?? 0), 2),
                (float) $weekly['values'][$index],
                0.05,
                "Weekly accrual mismatch for {$isoDate}"
            );
        }
    }

    public function test_grouped_room_status_counts_sum_to_total(): void
    {
        $user = $this->seedAndUser();
        $occ = app(RoomOccupancyService::class)->summaryForDate(Carbon::today());

        $grouped = ($occ['vacant'] ?? 0)
            + ($occ['reserved'] ?? 0)
            + ($occ['check_in_today'] ?? 0)
            + ($occ['check_out_today'] ?? 0)
            + ($occ['in_house'] ?? 0)
            + ($occ['pending'] ?? 0)
            + ($occ['preparation'] ?? 0)
            + ($occ['out_of_service'] ?? 0);

        $this->assertSame((int) ($occ['total'] ?? 0), (int) $grouped);
    }
}
