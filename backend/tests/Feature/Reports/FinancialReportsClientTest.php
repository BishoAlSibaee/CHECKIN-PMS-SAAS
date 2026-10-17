<?php

namespace Tests\Feature\Reports;

use App\Services\Reports\ReportQueryService;
use App\Services\Reports\ReportVerificationService;
use Carbon\Carbon;
use Database\Seeders\ReservationTestDataSeeder;
use Tests\Support\FinanceAssertions;
use Tests\Support\FinanceTestBootstrap;
use Tests\Support\RevenueSummaryExpectations;
use Tests\TestCase;

/**
 * Client-style tests: HTTP API responses must match canonical DB/services.
 */
class FinancialReportsClientTest extends TestCase
{
    use FinanceAssertions;
    use FinanceTestBootstrap;
    use RevenueSummaryExpectations;

    protected function tearDown(): void
    {
        $this->resetFinanceTestNow();
        parent::tearDown();
    }

    public function test_revenue_summary_client_http_matches_database_for_june_july_stay(): void
    {
        Carbon::setTestNow('2026-06-24 12:00:00');

        $this->artisan('db:seed', [
            '--class' => ReservationTestDataSeeder::class,
            '--force' => true,
            '--no-interaction' => true,
        ]);
        $this->artisan('reservations:backfill-daily-charges', ['--sync-base' => true]);

        $user = $this->userWithApiPermissions([
            'view reports',
            'view revenue',
            'view financial reports',
        ]);

        $start = '2026-06-01';
        $end = '2026-07-31';

        $httpData = $this->fetchFullFinanceReportViaHttp($user, 'revenue-summary', $start, $end);
        $this->assertRevenueSummaryReportMatchesCanonicalServices(
            $httpData,
            Carbon::parse($start),
            Carbon::parse($end),
            'revenue-summary HTTP'
        );

        $serviceData = app(ReportQueryService::class)->run('revenue-summary', [
            'start_date' => $start,
            'end_date' => $end,
        ]);
        foreach ($serviceData['summary'] ?? [] as $summaryRow) {
            $label = $summaryRow['label'];
            $httpValue = collect($httpData['summary'] ?? [])->firstWhere('label', $label)['value'] ?? null;
            $this->assertNotNull($httpValue, "HTTP summary missing {$label}");
            if (is_numeric($summaryRow['value']) && is_numeric($httpValue)) {
                $this->assertEqualsWithDelta((float) $summaryRow['value'], (float) $httpValue, 0.001, $label);
            } else {
                $this->assertSame($summaryRow['value'], $httpValue, $label);
            }
        }

        $verification = app(ReportVerificationService::class)->verifySlug(
            'revenue-summary',
            Carbon::parse($start),
            Carbon::parse($end),
            ['start_date' => $start, 'end_date' => $end]
        );
        $this->assertTrue($verification['pass'], $verification['message']);

        $byDate = collect($httpData['rows'] ?? [])->keyBy('charge_date');
        $futureDay = $byDate->get('2026-06-28');
        $this->assertNotNull($futureDay);
        $this->assertGreaterThan(0, (int) $futureDay['room_nights'], 'Booked nights on future in-stay day');
        $this->assertSame(0, (int) $futureDay['earned_room_nights']);
        $this->assertSame(0.0, (float) $futureDay['revenue']);

        $this->assertSame(2, (int) $byDate->get('2026-06-19')['active_bookings'], 'June 19 overlapping stays');
        $this->assertSame(2, (int) $byDate->get('2026-06-20')['active_bookings'], 'June 20 overlapping stays');
        $this->assertSame(1, (int) $byDate->get('2026-06-21')['active_bookings'], 'June 21 single stay');

        foreach (['2026-06-19', '2026-06-20', '2026-06-21'] as $day) {
            $listResponse = $this->actingAs($user, 'api')->getJson(
                '/api/users/reservations?date_from=' . $day . '&date_to=' . $day . '&perPage=50'
            );
            $listResponse->assertOk();
            $listTotal = (int) $listResponse->json('total');
            $this->assertSame(
                (int) $byDate->get($day)['active_bookings'],
                $listTotal,
                "Reservations list count for {$day}"
            );
        }
    }

    /** @dataProvider financialReportSlugProvider */
    public function test_financial_report_client_http_passes_canonical_verification(string $slug): void
    {
        $user = $this->bootstrapFinanceData();
        $start = Carbon::parse(self::FINANCE_PERIOD_START);
        $end = Carbon::parse(self::FINANCE_PERIOD_END);

        $params = [
            'start_date' => $start->toDateString(),
            'end_date' => $end->toDateString(),
        ];

        $httpData = $this->fetchFinanceReport($user, $slug, $start->toDateString(), $end->toDateString());

        $this->assertArrayHasKey('columns', $httpData);
        $this->assertArrayHasKey('summary', $httpData);
        $this->assertIsArray($httpData['rows'] ?? null);

        $verification = app(ReportVerificationService::class)->verifySlug($slug, $start, $end, $params);

        $this->assertTrue(
            $verification['pass'],
            "Financial report [{$slug}] failed verification: {$verification['message']}"
        );
    }

    public function test_all_financial_reports_http_summary_matches_service_output(): void
    {
        $user = $this->bootstrapFinanceData();
        $start = self::FINANCE_PERIOD_START;
        $end = self::FINANCE_PERIOD_END;

        foreach (self::financialReportSlugProvider() as [$slug]) {
            $httpData = $this->fetchFinanceReport($user, $slug, $start, $end);
            $serviceData = app(ReportQueryService::class)->run($slug, [
                'start_date' => $start,
                'end_date' => $end,
            ]);

            foreach ($serviceData['summary'] ?? [] as $index => $summaryRow) {
                $httpRow = $httpData['summary'][$index] ?? null;
                $this->assertNotNull($httpRow, "Missing summary row {$index} for {$slug}");
                $this->assertSame($summaryRow['label'], $httpRow['label']);
                $this->assertEqualsWithDelta(
                    (float) $summaryRow['value'],
                    (float) $httpRow['value'],
                    0.001,
                    "Summary value mismatch for {$slug} / {$summaryRow['label']}"
                );
            }
        }
    }

    /**
     * @return array<int, array{0: string}>
     */
    public static function financialReportSlugProvider(): array
    {
        $slugs = [
            'overview',
            'accrual-revenue',
            'cash-box',
            'revenue-summary',
            'accrual-cash-reconciliation',
            'ar-aging',
            'adjustments',
            'tax',
            'revpar',
            'by-dimension',
            'peak-analysis',
            'payments-refunds',
            'closing-package',
        ];

        return array_map(fn (string $slug) => [$slug], $slugs);
    }
}
