<?php

namespace Tests\Feature\Finance;

use App\Services\Reports\ReportCatalog;
use App\Services\Reports\ReportVerificationService;
use App\Services\RevenueAccrualService;
use Carbon\Carbon;
use Tests\Feature\Reports\AllReportsSlugTest;
use Tests\Support\FinanceAssertions;
use Tests\Support\FinanceTestBootstrap;
use Tests\TestCase;

class AllReportsGoldenValidationTest extends TestCase
{
    use \Illuminate\Foundation\Testing\DatabaseTransactions;

    protected function connectionsToTransact(): array
    {
        return ['mysql', 'mysql2'];
    }

    use FinanceAssertions;
    use FinanceTestBootstrap;

    public function test_all_report_slugs_pass_verification(): void
    {
        $this->bootstrapFinanceData();

        $verification = app(ReportVerificationService::class);
        $results = $verification->verifyAll(
            Carbon::parse(self::FINANCE_PERIOD_START),
            Carbon::parse(self::FINANCE_PERIOD_END)
        );

        // 26, not 23 — ReportCatalog grew the three accounting-export slugs
        // (generic/qoyod/daftra) after this golden count was written.
        $this->assertCount(26, $results);

        $failures = [];
        foreach ($results as $result) {
            if (! $result['pass']) {
                $failures[] = $result['slug'].': '.$result['message'];
            }
        }

        $this->assertEmpty($failures, "Report verification failures:\n".implode("\n", $failures));
    }

    public function test_financial_reports_via_http_match_service(): void
    {
        $user = $this->bootstrapFinanceData();
        $start = Carbon::parse(self::FINANCE_PERIOD_START);
        $end = Carbon::parse(self::FINANCE_PERIOD_END);
        $accrual = app(RevenueAccrualService::class)->calculate('total', null, $start, $end, false);
        $expectedRevenue = round((float) $accrual['current']['total'], 2);

        $slugs = ['overview', 'accrual-revenue', 'revenue-summary', 'closing-package', 'tax'];
        foreach ($slugs as $slug) {
            $data = $this->fetchFinanceReport($user, $slug);
            $this->assertArrayHasKey('columns', $data);
            $this->assertArrayHasKey('rows', $data);
        }

        $overview = $this->fetchFinanceReport($user, 'overview');
        $this->assertReportSummaryEquals($overview, 'Accrual revenue', $expectedRevenue, 0.10, 'overview');
    }

    public function test_accounting_reports_via_http_match_service(): void
    {
        $user = $this->bootstrapFinanceData();
        $start = Carbon::parse(self::FINANCE_PERIOD_START);
        $end = Carbon::parse(self::FINANCE_PERIOD_END);

        $trial = $this->fetchFinanceReport($user, 'trial-balance');
        $this->assertSame('Yes', $this->reportSummaryString($trial, 'Balanced'));

        $coa = $this->fetchFinanceReport($user, 'chart-of-accounts');
        $row4010 = collect($coa['rows'] ?? [])->firstWhere('code', '4010');
        $this->assertNotNull($row4010);

        $accrual = app(RevenueAccrualService::class)->calculate('total', null, $start, $end, false);
        $this->assertEqualsWithDelta(
            round((float) $accrual['current']['subtotal'], 2),
            (float) ($row4010['balance'] ?? 0),
            0.20
        );
    }

    public function test_operational_reports_have_expected_row_counts(): void
    {
        $user = $this->bootstrapFinanceData();

        $arrivals = $this->fetchFinanceReport($user, 'arrivals-departures');
        $this->assertGreaterThan(0, count($arrivals['rows'] ?? []));

        $list = $this->fetchFinanceReport($user, 'reservations-list');
        $this->assertSame(
            (int) ($this->reportSummaryValue($list, 'Reservations') ?? 0),
            count($list['rows'] ?? [])
        );

        $board = $this->fetchFinanceReport($user, 'room-board', self::FINANCE_PERIOD_END, self::FINANCE_PERIOD_END);
        $this->assertGreaterThan(0, (int) ($this->reportSummaryValue($board, 'Total rooms') ?? 0));
    }

    public function test_compare_period_reports_show_different_totals(): void
    {
        $user = $this->bootstrapFinanceData();
        $juneStart = '2026-06-01';
        $juneEnd = '2026-06-30';

        $june = app(RevenueAccrualService::class)->calculate(
            'total',
            null,
            Carbon::parse($juneStart),
            Carbon::parse($juneEnd),
            false
        );
        $august = app(RevenueAccrualService::class)->calculate(
            'total',
            null,
            Carbon::parse(self::FINANCE_PERIOD_START),
            Carbon::parse(self::FINANCE_PERIOD_END),
            false
        );

        $this->assertNotEquals(
            round((float) $june['current']['total'], 2),
            round((float) $august['current']['total'], 2),
            'Fixture should have different June vs August accrual'
        );

        $overview = $this->fetchFinanceReport($user, 'overview', self::FINANCE_PERIOD_START, self::FINANCE_PERIOD_END, [
            'compare_start_date' => $juneStart,
            'compare_end_date' => $juneEnd,
        ]);

        $compareAccrual = $this->reportSummaryValue($overview, 'Compare accrual');
        $this->assertNotNull($compareAccrual);
        $this->assertEqualsWithDelta(
            round((float) $june['current']['total'], 2),
            $compareAccrual,
            0.10
        );
        $this->assertReportSummaryEquals(
            $overview,
            'Accrual revenue',
            round((float) $august['current']['total'], 2),
            0.10,
            'overview current'
        );
    }

    public function test_report_catalog_lists_all_slugs(): void
    {
        // 26, not 23 — same stale count as above.
        $this->assertCount(26, ReportCatalog::allSlugs());
        $providerSlugs = array_map(fn (array $row) => $row[0], AllReportsSlugTest::reportSlugProvider());
        $this->assertSame(ReportCatalog::allSlugs(), $providerSlugs);
    }
}
