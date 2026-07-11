<?php

namespace Tests\Feature\Finance;

use App\Services\RevenueAccrualService;
use Carbon\Carbon;
use Tests\Feature\Reports\AllReportsSlugTest;
use Tests\Support\FinanceAssertions;
use Tests\Support\FinanceTestBootstrap;
use Tests\TestCase;

class ReportsFinancialConsistencyTest extends TestCase
{
    use FinanceAssertions;
    use FinanceTestBootstrap;

    /** @dataProvider financialReportSlugProvider */
    public function test_financial_report_slugs_match_accrual_service(string $slug): void
    {
        $user = $this->bootstrapFinanceData();
        $start = self::FINANCE_PERIOD_START;
        $end = self::FINANCE_PERIOD_END;

        $data = $this->fetchFinanceReport($user, $slug, $start, $end);

        $service = app(RevenueAccrualService::class);
        $accrual = $service->calculate('total', null, Carbon::parse($start), Carbon::parse($end), false);
        $expectedRevenue = round((float) $accrual['current']['total'], 2);
        $expectedTax = round((float) $accrual['current']['tax'], 2);
        $expectedNights = (int) $accrual['current']['count'];

        if ($slug === 'overview') {
            $this->assertReportSummaryEquals($data, 'Accrual revenue', $expectedRevenue, 0.05);
        }

        if ($slug === 'accrual-revenue') {
            $this->assertReportSummaryEquals($data, 'Total revenue', $expectedRevenue, 0.05);
            $this->assertReportSummaryEquals($data, 'Total tax', $expectedTax, 0.05);
            $this->assertEquals($expectedNights, (int) ($this->reportSummaryValue($data, 'Room nights') ?? 0));
            $this->assertRowSumEquals($data['rows'] ?? [], 'revenue', $expectedRevenue, 0.10);
        }

        if ($slug === 'revenue-summary') {
            $this->assertReportSummaryEquals($data, 'Total revenue', $expectedRevenue, 0.05);
            $this->assertRowSumEquals($data['rows'] ?? [], 'revenue', $expectedRevenue, 0.10);
            $this->assertRowSumEquals($data['rows'] ?? [], 'earned_room_nights', $expectedNights, 0);
            $this->assertReportSummaryEquals($data, 'Room nights (earned)', (float) $expectedNights, 0);
        }

        if ($slug === 'tax') {
            $reportTax = $this->reportSummaryValue($data, 'Total tax');
            if ($reportTax !== null) {
                $this->assertEqualsWithDelta($expectedTax, $reportTax, 0.10);
            }
        }
    }

    public static function financialReportSlugProvider(): array
    {
        $financial = [
            'overview',
            'accrual-revenue',
            'cash-box',
            'revenue-summary',
            'accrual-cash-reconciliation',
            'tax',
            'payments-refunds',
            'adjustments',
            'by-dimension',
            'peak-analysis',
            'revpar',
            'trial-balance',
            'cash-flow',
            'general-ledger',
            'financial-audit-log',
            'closing-package',
        ];

        return array_map(fn ($slug) => [$slug], $financial);
    }

    public function test_all_report_slugs_still_valid(): void
    {
        $provider = AllReportsSlugTest::reportSlugProvider();
        $this->assertGreaterThanOrEqual(23, count($provider));
    }
}
