<?php

namespace Tests\Feature\Finance;

use App\Services\RevenueAccrualService;
use Carbon\Carbon;
use Tests\Support\FinanceAssertions;
use Tests\Support\FinanceTestBootstrap;
use Tests\TestCase;

class FinancialApiGoldenTest extends TestCase
{
    use \Illuminate\Foundation\Testing\DatabaseTransactions;

    protected function connectionsToTransact(): array
    {
        return ['mysql', 'mysql2'];
    }

    use FinanceAssertions;
    use FinanceTestBootstrap;

    public function test_dashboard_matches_accrual_and_cash_reports(): void
    {
        $user = $this->bootstrapFinanceData();
        $start = self::FINANCE_PERIOD_START;
        $end = self::FINANCE_PERIOD_END;

        $accrual = app(RevenueAccrualService::class)->calculate(
            'total',
            null,
            Carbon::parse($start),
            Carbon::parse($end),
            false
        );
        $expectedRevenue = round((float) $accrual['current']['total'], 2);

        $dash = $this->actingAs($user, 'api')->getJson(
            "/api/users/financials/dashboard?start_date={$start}&end_date={$end}"
        );
        $dash->assertOk();
        $kpis = $dash->json('data.kpis');

        $overview = $this->fetchFinanceReport($user, 'overview');
        $cashBox = $this->fetchFinanceReport($user, 'cash-box');

        $this->assertEqualsWithDelta($expectedRevenue, (float) ($kpis['accrual_total'] ?? 0), 0.05);
        $this->assertReportSummaryEquals($overview, 'Accrual revenue', $expectedRevenue, 0.10);
        $this->assertEqualsWithDelta(
            (float) ($kpis['cash_net'] ?? 0),
            $this->reportSummaryValue($cashBox, 'Net') ?? 0,
            0.10
        );
        $this->assertEqualsWithDelta(
            (float) ($kpis['ar_balance'] ?? 0),
            $this->reportSummaryValue($overview, 'A/R balance') ?? 0,
            0.15
        );
    }

    public function test_revenue_total_matches_accrual_revenue_report(): void
    {
        $user = $this->bootstrapFinanceData();
        $start = self::FINANCE_PERIOD_START;
        $end = self::FINANCE_PERIOD_END;

        $revApi = $this->actingAs($user, 'api')->getJson(
            "/api/users/revenue/total?start_date={$start}&end_date={$end}&include_details=0"
        );
        $revApi->assertOk();
        $apiTotal = (float) ($revApi->json('data.revenue.current.total') ?? 0);

        $report = $this->fetchFinanceReport($user, 'accrual-revenue');
        $this->assertReportSummaryEquals($report, 'Total revenue', $apiTotal, 0.05);
    }

    public function test_earnings_summary_matches_cash_box_report(): void
    {
        $user = $this->bootstrapFinanceData();
        $start = self::FINANCE_PERIOD_START;
        $end = self::FINANCE_PERIOD_END;

        $earn = $this->actingAs($user, 'api')->getJson(
            "/api/users/earnings-summary?start_date={$start}&end_date={$end}"
        );
        $earn->assertOk();

        $cashBox = $this->fetchFinanceReport($user, 'cash-box');
        $this->assertEqualsWithDelta(
            (float) ($earn->json('data.total_in') ?? 0),
            $this->reportSummaryValue($cashBox, 'Cash in') ?? 0,
            0.10
        );
        $this->assertEqualsWithDelta(
            (float) ($earn->json('data.net_earnings') ?? 0),
            $this->reportSummaryValue($cashBox, 'Net') ?? 0,
            0.10
        );
    }

    public function test_accounting_chart_of_accounts_matches_report(): void
    {
        $user = $this->bootstrapFinanceData();
        $start = self::FINANCE_PERIOD_START;
        $end = self::FINANCE_PERIOD_END;

        $api = $this->actingAs($user, 'api')->getJson(
            "/api/users/accounting/chart-of-accounts?start_date={$start}&end_date={$end}"
        );
        $api->assertOk();

        $report = $this->fetchFinanceReport($user, 'chart-of-accounts');

        $this->assertContains('name', collect($report['columns'] ?? [])->pluck('key'));
        $this->assertNotContains('name_en', collect($report['columns'] ?? [])->pluck('key'));
        $this->assertNotContains('name_ar', collect($report['columns'] ?? [])->pluck('key'));
    }

    public function test_by_dimension_report_matches_revenue_detail_sum(): void
    {
        $user = $this->bootstrapFinanceData();
        $start = Carbon::parse(self::FINANCE_PERIOD_START);
        $end = Carbon::parse(self::FINANCE_PERIOD_END);

        $expected = round((float) app(RevenueAccrualService::class)->calculate('total', null, $start, $end, false)['current']['total'], 2);
        $report = $this->fetchFinanceReport($user, 'by-dimension');
        $this->assertRowSumEquals($report['rows'] ?? [], 'revenue', $expected, 0.15, 'by-dimension');
    }
}
