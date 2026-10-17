<?php

namespace Tests\Feature\Finance;

use Carbon\Carbon;
use Database\Seeders\ReservationTestDataSeeder;
use Tests\TestCase;

class FinancialDashboardTest extends TestCase
{
    private function seedAndUser(): \App\Models\User
    {
        $this->artisan('db:seed', [
            '--class' => ReservationTestDataSeeder::class,
            '--force' => true,
            '--no-interaction' => true,
        ]);

        return $this->userWithApiPermissions([
            'view revenue',
            'view earnings',
        ]);
    }

    public function test_financial_dashboard_matches_revenue_and_earnings_for_may_2026(): void
    {
        $user = $this->seedAndUser();

        $dashboard = $this->actingAs($user, 'api')->getJson(
            '/api/users/financials/dashboard?start_date=2026-05-01&end_date=2026-05-31'
        );
        $dashboard->assertOk();
        $dashboard->assertJsonPath('success', true);

        $revenue = $this->actingAs($user, 'api')->getJson(
            '/api/users/revenue/total?start_date=2026-05-01&end_date=2026-05-31&include_details=0'
        );
        $revenue->assertOk();

        $earnings = $this->actingAs($user, 'api')->getJson(
            '/api/users/earnings-summary?start_date=2026-05-01&end_date=2026-05-31'
        );
        $earnings->assertOk();

        $kpis = $dashboard->json('data.kpis');
        $revTotal = (float) ($revenue->json('data.revenue.current.total') ?? $revenue->json('data.current.total') ?? 0);
        $earnIn = (float) ($earnings->json('data.total_in') ?? 0);
        $earnOut = (float) ($earnings->json('data.total_out') ?? 0);
        $earnNet = (float) ($earnings->json('data.net_earnings') ?? 0);

        $this->assertEqualsWithDelta($revTotal, (float) $kpis['accrual_total'], 0.05);
        $this->assertEqualsWithDelta($earnIn, (float) $kpis['cash_in'], 0.05);
        $this->assertEqualsWithDelta($earnOut, (float) $kpis['cash_out'], 0.05);
        $this->assertEqualsWithDelta($earnNet, (float) $kpis['cash_net'], 0.05);

        $series = $dashboard->json('data.series');
        $this->assertIsArray($series);
        $this->assertNotEmpty($series);
        $this->assertContains($dashboard->json('data.series_granularity'), ['day', 'week', 'month']);

        $sumAccrual = array_sum(array_column($series, 'accrual'));
        $this->assertEqualsWithDelta($revTotal, $sumAccrual, 1.0, 'Series accrual should sum to period total');
    }

    public function test_financial_bounds_returns_dates(): void
    {
        $user = $this->seedAndUser();

        $response = $this->actingAs($user, 'api')->getJson('/api/users/financials/bounds');
        $response->assertOk();
        $response->assertJsonPath('success', true);
        $this->assertNotNull($response->json('data.earliest_charge_date'));
    }

    public function test_weekly_series_buckets_align_with_accrual_data(): void
    {
        $user = $this->seedAndUser();

        $dashboard = $this->actingAs($user, 'api')->getJson(
            '/api/users/financials/dashboard?start_date=2026-01-01&end_date=2026-12-31'
        );
        $dashboard->assertOk();
        $dashboard->assertJsonPath('data.series_granularity', 'week');

        $kpis = $dashboard->json('data.kpis');
        $series = $dashboard->json('data.series');
        $this->assertIsArray($series);
        $this->assertNotEmpty($series);

        $sumAccrual = array_sum(array_column($series, 'accrual'));
        $accrualTotal = (float) ($kpis['accrual_total'] ?? 0);

        $this->assertGreaterThan(0, $accrualTotal, 'Fixture should include accrual in 2026');
        $this->assertEqualsWithDelta(
            $accrualTotal,
            $sumAccrual,
            1.0,
            'Weekly buckets must align with accrual aggregation keys'
        );

        $firstMonday = Carbon::parse('2026-01-01')->startOfWeek(Carbon::MONDAY)->toDateString();
        $this->assertSame($firstMonday, $series[0]['bucket']);
    }
}
