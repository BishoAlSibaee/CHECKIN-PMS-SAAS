<?php

namespace Tests\Feature\Reports;

use App\Models\Reservation;
use App\Services\Reports\ReportQueryService;
use Carbon\Carbon;
use Database\Seeders\ReservationTestDataSeeder;
use Tests\Support\FinanceTestBootstrap;
use Tests\Support\RevenueSummaryExpectations;
use Tests\TestCase;

class AccrualRevenueReportTest extends TestCase
{
    use FinanceTestBootstrap;
    use RevenueSummaryExpectations;

    public function test_june_july_period_returns_accrual_rows_via_http(): void
    {
        Carbon::setTestNow('2026-06-24');

        $this->artisan('db:seed', [
            '--class' => ReservationTestDataSeeder::class,
            '--force' => true,
            '--no-interaction' => true,
        ]);

        $user = $this->userWithApiPermissions([
            'view reports',
            'view revenue',
            'view financial reports',
        ]);

        $response = $this->actingAs($user, 'api')->getJson(
            '/api/users/reports/accrual-revenue?start_date=2026-06-01&end_date=2026-07-31'
        );

        $response->assertOk();
        $response->assertJsonPath('success', true);

        $data = $response->json('data') ?? [];
        $this->assertGreaterThan(0, count($data['rows'] ?? []));
        $summary = collect($data['summary'] ?? [])->pluck('value', 'label');
        $this->assertGreaterThan(0, (float) $summary->get('Total revenue', 0));

        Carbon::setTestNow();
    }

    public function test_includes_one_row_per_calendar_day_not_per_charge_line(): void
    {
        Carbon::setTestNow('2026-06-24');

        $this->artisan('db:seed', [
            '--class' => ReservationTestDataSeeder::class,
            '--force' => true,
            '--no-interaction' => true,
        ]);

        $report = app(ReportQueryService::class)->run('accrual-revenue', [
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-30',
        ]);

        $this->assertCount(30, $report['rows']);
        $dates = collect($report['rows'])->pluck('charge_date');
        $this->assertSame($dates->count(), $dates->unique()->count(), 'Each date must appear once');

        Carbon::setTestNow();
    }

    public function test_june_overlap_day_shows_two_guests_on_single_row(): void
    {
        Carbon::setTestNow('2026-06-24');

        $this->artisan('db:seed', [
            '--class' => ReservationTestDataSeeder::class,
            '--force' => true,
            '--no-interaction' => true,
        ]);

        $report = app(ReportQueryService::class)->run('accrual-revenue', [
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-30',
        ]);

        $byDate = collect($report['rows'])->keyBy('charge_date');
        $june19 = $byDate->get('2026-06-19');
        $june20 = $byDate->get('2026-06-20');

        $this->assertNotNull($june19);
        $this->assertSame(2, (int) $june19['active_bookings']);
        $this->assertGreaterThan(0, (int) $june19['room_nights']);
        $this->assertStringContainsString('Ahmed', (string) $june19['guest']);
        $this->assertStringContainsString('Nour', (string) $june19['guest']);

        $this->assertNotNull($june20);
        $this->assertSame(2, (int) $june20['active_bookings']);
        $this->assertGreaterThan(0, (int) $june20['room_nights']);
        $this->assertStringContainsString('Ahmed', (string) $june20['guest']);
        $this->assertStringContainsString('Nour', (string) $june20['guest']);

        Carbon::setTestNow();
    }

    public function test_guest_names_are_populated_on_days_with_revenue(): void
    {
        Carbon::setTestNow('2026-06-24');

        $this->artisan('db:seed', [
            '--class' => ReservationTestDataSeeder::class,
            '--force' => true,
            '--no-interaction' => true,
        ]);

        $report = app(ReportQueryService::class)->run('accrual-revenue', [
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-30',
        ]);

        $withRevenue = collect($report['rows'])->filter(fn ($row) => (float) ($row['revenue'] ?? 0) > 0);
        $this->assertGreaterThan(0, $withRevenue->count());

        foreach ($withRevenue as $row) {
            $guest = trim((string) ($row['guest'] ?? ''));
            $this->assertNotSame('', $guest);
            $this->assertNotSame('—', $guest, "Guest must be set on {$row['charge_date']}");
        }

        Carbon::setTestNow();
    }

    public function test_active_bookings_match_reservations_overlap_rule(): void
    {
        Carbon::setTestNow('2026-06-24');

        $this->artisan('db:seed', [
            '--class' => ReservationTestDataSeeder::class,
            '--force' => true,
            '--no-interaction' => true,
        ]);

        $report = app(ReportQueryService::class)->run('accrual-revenue', [
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-30',
        ]);

        foreach ($report['rows'] as $row) {
            $this->assertSame(
                $this->expectedActiveBookingsForDate((string) $row['charge_date']),
                (int) $row['active_bookings']
            );
        }

        Carbon::setTestNow();
    }

    public function test_row_revenue_sums_match_summary_total(): void
    {
        Carbon::setTestNow('2026-06-24');

        $this->artisan('db:seed', [
            '--class' => ReservationTestDataSeeder::class,
            '--force' => true,
            '--no-interaction' => true,
        ]);

        $report = app(ReportQueryService::class)->run('accrual-revenue', [
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-30',
        ]);

        $rowRevenue = round(collect($report['rows'])->sum('revenue'), 2);
        $summaryRevenue = (float) collect($report['summary'])->firstWhere('label', 'Total revenue')['value'];

        $this->assertEqualsWithDelta($summaryRevenue, $rowRevenue, 0.02);

        Carbon::setTestNow();
    }

    public function test_guest_names_include_active_stays_even_without_earned_revenue(): void
    {
        Carbon::setTestNow('2026-06-24');

        $this->artisan('db:seed', [
            '--class' => ReservationTestDataSeeder::class,
            '--force' => true,
            '--no-interaction' => true,
        ]);

        $report = app(ReportQueryService::class)->run('accrual-revenue', [
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-30',
        ]);

        $june20 = collect($report['rows'])->firstWhere('charge_date', '2026-06-20');
        $this->assertNotNull($june20);
        $this->assertSame(2, (int) $june20['active_bookings']);
        $this->assertStringContainsString('Nour', (string) $june20['guest']);
        $this->assertStringContainsString('Ahmed', (string) $june20['guest']);

        Carbon::setTestNow();
    }

    public function test_cross_month_confirmed_stay_appears_from_start_date_in_june(): void
    {
        Carbon::setTestNow('2026-06-24');

        $this->artisan('db:seed', [
            '--class' => ReservationTestDataSeeder::class,
            '--force' => true,
            '--no-interaction' => true,
        ]);

        $report = app(ReportQueryService::class)->run('accrual-revenue', [
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-30',
        ]);

        $june19 = collect($report['rows'])->firstWhere('charge_date', '2026-06-19');
        $this->assertNotNull($june19);
        $this->assertStringContainsString('Ahmed', (string) $june19['guest']);
        $this->assertGreaterThan(0, (float) $june19['revenue']);

        $julyReport = app(ReportQueryService::class)->run('accrual-revenue', [
            'start_date' => '2026-07-01',
            'end_date' => '2026-07-31',
        ]);
        $july1 = collect($julyReport['rows'])->firstWhere('charge_date', '2026-07-01');
        $this->assertNotNull($july1);
        $this->assertStringContainsString('Ahmed', (string) $july1['guest']);
        $this->assertSame(1, (int) $july1['active_bookings']);
        $this->assertSame(0.0, (float) $july1['revenue'], 'Future nights are not earned before the calendar date');

        Carbon::setTestNow();
    }
}
