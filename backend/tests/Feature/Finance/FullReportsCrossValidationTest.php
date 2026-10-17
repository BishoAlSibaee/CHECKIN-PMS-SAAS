<?php

namespace Tests\Feature\Finance;

use App\Models\ReservationDailyCharge;
use App\Models\Room;
use App\Services\Accounting\FinancialStatementService;
use App\Services\RevenueAccrualService;
use Carbon\Carbon;
use Tests\Support\FinanceAssertions;
use Tests\Support\FinanceTestBootstrap;
use Tests\TestCase;

/**
 * Cross-validate report summaries and dashboard KPIs against canonical services (Aug 2026).
 */
class FullReportsCrossValidationTest extends TestCase
{
    use FinanceAssertions;
    use FinanceTestBootstrap;

    private function report(\App\Models\User $user, string $slug): array
    {
        return $this->fetchFinanceReport($user, $slug);
    }

    public function test_accrual_reports_and_dashboard_match_service(): void
    {
        $user = $this->bootstrapFinanceData();
        $start = Carbon::parse(self::FINANCE_PERIOD_START);
        $end = Carbon::parse(self::FINANCE_PERIOD_END);

        $svc = app(RevenueAccrualService::class);
        $accrual = $svc->calculate('total', null, $start, $end, false);
        $expectedRevenue = round((float) $accrual['current']['total'], 2);
        $expectedTax = round((float) $accrual['current']['tax'], 2);
        $expectedNights = (int) $accrual['current']['count'];

        $overview = $this->report($user, 'overview');
        $accrualRep = $this->report($user, 'accrual-revenue');
        $revSum = $this->report($user, 'revenue-summary');
        $recon = $this->report($user, 'accrual-cash-reconciliation');
        $closing = $this->report($user, 'closing-package');

        foreach ([
            ['overview', $this->reportSummaryValue($overview, 'Accrual revenue')],
            ['accrual-revenue', $this->reportSummaryValue($accrualRep, 'Total revenue')],
            ['revenue-summary', $this->reportSummaryValue($revSum, 'Total revenue')],
            ['reconciliation', $this->reportSummaryValue($recon, 'Accrual revenue')],
            ['closing-package', $this->reportSummaryValue($closing, 'Accrual revenue')],
        ] as [$name, $value]) {
            $this->assertEqualsWithDelta($expectedRevenue, $value ?? 0, 0.05, "{$name} revenue");
        }

        $this->assertEqualsWithDelta($expectedTax, $this->reportSummaryValue($accrualRep, 'Total tax') ?? 0, 0.05);
        $this->assertEquals($expectedNights, (int) ($this->reportSummaryValue($accrualRep, 'Room nights') ?? 0));

        $rowRevenue = round(collect($accrualRep['rows'] ?? [])->sum(fn ($r) => (float) ($r['revenue'] ?? 0)), 2);
        $this->assertEqualsWithDelta($expectedRevenue, $rowRevenue, 0.15);

        $dash = $this->actingAs($user, 'api')->getJson(
            '/api/users/financials/dashboard?start_date='.self::FINANCE_PERIOD_START.'&end_date='.self::FINANCE_PERIOD_END
        );
        $dash->assertOk();
        $kpis = $dash->json('data.kpis');
        $this->assertEqualsWithDelta($expectedRevenue, (float) ($kpis['accrual_total'] ?? 0), 0.05);

        $revApi = $this->actingAs($user, 'api')->getJson(
            '/api/users/revenue/total?start_date='.self::FINANCE_PERIOD_START.'&end_date='.self::FINANCE_PERIOD_END.'&include_details=0'
        );
        $revApi->assertOk();
        $this->assertEqualsWithDelta(
            $expectedRevenue,
            (float) ($revApi->json('data.revenue.current.total') ?? 0),
            0.05
        );
    }

    public function test_cash_reports_match_earnings_summary(): void
    {
        $user = $this->bootstrapFinanceData();

        $earn = $this->actingAs($user, 'api')->getJson(
            '/api/users/earnings-summary?start_date='.self::FINANCE_PERIOD_START.'&end_date='.self::FINANCE_PERIOD_END
        );
        $earn->assertOk();
        $cashIn = (float) ($earn->json('data.total_in') ?? 0);
        $cashOut = (float) ($earn->json('data.total_out') ?? 0);
        $cashNet = (float) ($earn->json('data.net_earnings') ?? 0);

        $dash = $this->actingAs($user, 'api')->getJson(
            '/api/users/financials/dashboard?start_date='.self::FINANCE_PERIOD_START.'&end_date='.self::FINANCE_PERIOD_END
        );
        $kpis = $dash->json('data.kpis');
        $this->assertEqualsWithDelta($cashIn, (float) ($kpis['cash_in'] ?? 0), 0.05);
        $this->assertEqualsWithDelta($cashOut, (float) ($kpis['cash_out'] ?? 0), 0.05);
        $this->assertEqualsWithDelta($cashNet, (float) ($kpis['cash_net'] ?? 0), 0.05);

        $cashBox = $this->report($user, 'cash-box');
        $payments = $this->report($user, 'payments-refunds');

        $this->assertEqualsWithDelta($cashIn, $this->reportSummaryValue($cashBox, 'Cash in') ?? 0, 0.10);
        $this->assertEqualsWithDelta($cashNet, $this->reportSummaryValue($cashBox, 'Net') ?? 0, 0.10);
        $this->assertEqualsWithDelta($cashIn, $this->reportSummaryValue($payments, 'Cash in') ?? 0, 0.10);
        $this->assertEqualsWithDelta($cashNet, $this->reportSummaryValue($payments, 'Net') ?? 0, 0.10);

        $recon = $this->report($user, 'accrual-cash-reconciliation');
        $this->assertEqualsWithDelta($cashNet, $this->reportSummaryValue($recon, 'Cash net') ?? 0, 0.10);
    }

    public function test_revpar_and_occupancy_match_accrual_and_charges(): void
    {
        $user = $this->bootstrapFinanceData();
        $start = Carbon::parse(self::FINANCE_PERIOD_START);
        $end = Carbon::parse(self::FINANCE_PERIOD_END);

        $accrual = app(RevenueAccrualService::class)->calculate('total', null, $start, $end, false);
        $expectedRevenue = round((float) $accrual['current']['total'], 2);
        $expectedSubtotal = round((float) $accrual['current']['subtotal'], 2);
        $expectedNights = (int) $accrual['current']['count'];

        $revpar = $this->report($user, 'revpar');
        $this->assertEqualsWithDelta($expectedRevenue, $this->reportSummaryValue($revpar, 'Revenue (incl. tax)') ?? 0, 0.05);

        $roomCount = Room::query()->where('active', 1)->whereNotIn('roomStatus', [3, 4])->count();
        $days = $start->diffInDays($end) + 1;
        $avail = $roomCount * $days;
        $expectedRevpar = $avail > 0 ? round($expectedRevenue / $avail, 2) : 0.0;
        $expectedAdr = $expectedNights > 0 ? round($expectedSubtotal / $expectedNights, 2) : 0.0;

        $this->assertEqualsWithDelta($expectedRevpar, $this->reportSummaryValue($revpar, 'RevPAR (revenue / available room nights)') ?? 0, 0.05);
        $this->assertEqualsWithDelta($expectedAdr, $this->reportSummaryValue($revpar, 'ADR (subtotal / room nights)') ?? 0, 0.05);

        $occupancy = $this->report($user, 'occupancy');
        $soldNights = (int) ($this->reportSummaryValue($occupancy, 'Total room nights sold') ?? 0);

        $chargeCount = ReservationDailyCharge::query()
            ->join('reservations', 'reservation_daily_charges.reservation_id', '=', 'reservations.id')
            ->where('reservations.reservation_status', 1)
            ->whereBetween('reservation_daily_charges.charge_date', [self::FINANCE_PERIOD_START, self::FINANCE_PERIOD_END])
            ->count();
        $this->assertSame($chargeCount, $soldNights);

        $capacity = Room::query()->where('active', 1)->whereNotIn('roomStatus', [3, 4])->count()
            * (int) ($this->reportSummaryValue($occupancy, 'Days in period') ?? 1);
        $expectedOcc = $capacity > 0 ? round($chargeCount / $capacity * 100, 1) : 0.0;
        $avgOcc = $this->parsePercentSummary($occupancy, 'Average occupancy rate');
        $this->assertEqualsWithDelta($expectedOcc, $avgOcc, 0.2);
    }

    public function test_gl_accounts_match_accrual_for_period(): void
    {
        $user = $this->bootstrapFinanceData();
        $start = Carbon::parse(self::FINANCE_PERIOD_START);
        $end = Carbon::parse(self::FINANCE_PERIOD_END);

        $accrual = app(RevenueAccrualService::class)->calculate('total', null, $start, $end, false);
        $balances = app(FinancialStatementService::class)->accountBalances($start, $end)->keyBy('code');

        $this->assertEqualsWithDelta(
            round((float) $accrual['current']['subtotal'], 2),
            round((float) ($balances->get('4010')->balance ?? 0), 2),
            0.20
        );
        $this->assertEqualsWithDelta(
            round((float) $accrual['current']['tax'], 2),
            round((float) ($balances->get('2150')->balance ?? 0), 2),
            0.20
        );

        $trial = app(FinancialStatementService::class)->trialBalance($start, $end);
        $this->assertTrue($trial['totals']['balanced']);

        $coa = $this->report($user, 'chart-of-accounts');
        $row4010 = collect($coa['rows'] ?? [])->firstWhere('code', '4010');
        $this->assertNotNull($row4010);
        $this->assertEqualsWithDelta(
            round((float) $accrual['current']['subtotal'], 2),
            (float) ($row4010['balance'] ?? 0),
            0.20
        );
    }

    public function test_tax_report_matches_accrual_tax(): void
    {
        $user = $this->bootstrapFinanceData();
        $start = Carbon::parse(self::FINANCE_PERIOD_START);
        $end = Carbon::parse(self::FINANCE_PERIOD_END);
        $expectedTax = round((float) app(RevenueAccrualService::class)->calculate('total', null, $start, $end, false)['current']['tax'], 2);

        $taxReport = $this->report($user, 'tax');
        $reportTax = $this->reportSummaryValue($taxReport, 'Total tax');
        if ($reportTax !== null) {
            $this->assertEqualsWithDelta($expectedTax, $reportTax, 0.10);
        }
    }
}
