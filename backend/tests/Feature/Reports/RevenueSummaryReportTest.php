<?php

namespace Tests\Feature\Reports;

use App\Services\Reports\ReportQueryService;
use Carbon\Carbon;
use Database\Seeders\ReservationTestDataSeeder;
use Tests\Support\FinanceTestBootstrap;
use Tests\Support\RevenueSummaryExpectations;
use Tests\TestCase;

class RevenueSummaryReportTest extends TestCase
{
    use FinanceTestBootstrap;
    use RevenueSummaryExpectations;
    public function test_includes_every_calendar_day_in_the_period(): void
    {
        Carbon::setTestNow('2026-06-30');

        $this->artisan('db:seed', ['--class' => ReservationTestDataSeeder::class, '--force' => true, '--no-interaction' => true]);

        $start = '2026-06-01';
        $end = '2026-06-30';

        $report = app(ReportQueryService::class)->run('revenue-summary', [
            'start_date' => $start,
            'end_date' => $end,
        ]);

        $this->assertCount(30, $report['rows']);
        $this->assertSame('2026-06-01', $report['rows'][0]['charge_date']);
        $this->assertSame('2026-06-30', $report['rows'][29]['charge_date']);

        Carbon::setTestNow();
    }

    public function test_hide_zero_removes_idle_days_without_changing_period_totals(): void
    {
        Carbon::setTestNow('2026-06-30');

        $this->artisan('db:seed', ['--class' => ReservationTestDataSeeder::class, '--force' => true, '--no-interaction' => true]);

        // January is outside the seeded demo stay window, so most/all days are idle zeros.
        $full = app(ReportQueryService::class)->run('revenue-summary', [
            'start_date' => '2026-01-01',
            'end_date' => '2026-01-15',
        ]);

        $filtered = app(ReportQueryService::class)->run('revenue-summary', [
            'start_date' => '2026-01-01',
            'end_date' => '2026-01-15',
            'hide_zero' => true,
        ]);

        $this->assertCount(15, $full['rows']);
        $this->assertLessThan(count($full['rows']), count($filtered['rows']));
        $this->assertSame(
            collect($full['summary'])->firstWhere('label', 'Total revenue')['value'],
            collect($filtered['summary'])->firstWhere('label', 'Total revenue')['value']
        );
        $this->assertSame(15, (int) collect($filtered['summary'])->firstWhere('label', 'Days in period')['value']);
        $this->assertSame(count($filtered['rows']), (int) collect($filtered['summary'])->firstWhere('label', 'Days shown')['value']);

        foreach ($filtered['rows'] as $row) {
            $hasActivity = (int) $row['active_bookings'] > 0
                || (int) $row['room_nights'] > 0
                || (int) $row['earned_room_nights'] > 0
                || abs((float) $row['revenue']) >= 0.005
                || abs((float) $row['base_amount']) >= 0.005
                || abs((float) $row['subtotal']) >= 0.005
                || abs((float) $row['tax']) >= 0.005;

            $this->assertTrue($hasActivity, 'Filtered row should not be an idle zero day: '.$row['charge_date']);
        }

        Carbon::setTestNow();
    }

    public function test_hide_zero_does_not_keep_days_with_only_in_house_snapshot(): void
    {
        Carbon::setTestNow('2026-06-30');

        $this->artisan('db:seed', ['--class' => ReservationTestDataSeeder::class, '--force' => true, '--no-interaction' => true]);

        $filtered = app(ReportQueryService::class)->run('revenue-summary', [
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'hide_zero' => true,
        ]);

        foreach ($filtered['rows'] as $row) {
            $moneyAndStay = (int) $row['active_bookings'] > 0
                || (int) $row['room_nights'] > 0
                || (int) $row['earned_room_nights'] > 0
                || abs((float) $row['revenue']) >= 0.005
                || abs((float) $row['base_amount']) >= 0.005
                || abs((float) $row['subtotal']) >= 0.005
                || abs((float) $row['tax']) >= 0.005;

            $this->assertTrue(
                $moneyAndStay,
                'hide_zero must not keep an in_house-only day: '.$row['charge_date']
            );
        }

        Carbon::setTestNow();
    }

    public function test_pending_payment_days_show_active_bookings_without_booked_nights(): void
    {
        Carbon::setTestNow('2026-06-30');

        $this->artisan('db:seed', ['--class' => ReservationTestDataSeeder::class, '--force' => true, '--no-interaction' => true]);

        $report = app(ReportQueryService::class)->run('revenue-summary', [
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-30',
        ]);

        $byDate = collect($report['rows'])->keyBy('charge_date');
        $pendingStayDay = $byDate->get('2026-06-15');

        $this->assertNotNull($pendingStayDay);
        $this->assertGreaterThan(
            (int) $pendingStayDay['room_nights'],
            (int) $pendingStayDay['active_bookings'],
            'Active bookings should include pending stays without nightly charges.'
        );
        $this->assertGreaterThan(0, (int) $pendingStayDay['in_house']);

        Carbon::setTestNow();
    }

    public function test_active_bookings_match_reservations_list_overlap_rule(): void
    {
        Carbon::setTestNow('2026-06-24');

        $this->artisan('db:seed', ['--class' => ReservationTestDataSeeder::class, '--force' => true, '--no-interaction' => true]);

        $report = app(ReportQueryService::class)->run('revenue-summary', [
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

    public function test_june_overlap_days_show_two_then_one_active_bookings(): void
    {
        Carbon::setTestNow('2026-06-24');

        $this->artisan('db:seed', ['--class' => ReservationTestDataSeeder::class, '--force' => true, '--no-interaction' => true]);

        $report = app(ReportQueryService::class)->run('revenue-summary', [
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-30',
        ]);

        $byDate = collect($report['rows'])->keyBy('charge_date');

        $this->assertSame(2, (int) $byDate->get('2026-06-19')['active_bookings']);
        $this->assertSame(2, (int) $byDate->get('2026-06-20')['active_bookings']);
        $this->assertSame(1, (int) $byDate->get('2026-06-21')['active_bookings']);

        Carbon::setTestNow();
    }

    public function test_future_nights_in_active_stay_show_booked_but_not_earned(): void
    {
        Carbon::setTestNow('2026-06-24');

        $this->artisan('db:seed', ['--class' => ReservationTestDataSeeder::class, '--force' => true, '--no-interaction' => true]);

        $report = app(ReportQueryService::class)->run('revenue-summary', [
            'start_date' => '2026-06-01',
            'end_date' => '2026-07-31',
        ]);

        $byDate = collect($report['rows'])->keyBy('charge_date');
        $futureDay = $byDate->get('2026-06-28');

        $this->assertNotNull($futureDay);
        $this->assertGreaterThan(0, (int) $futureDay['room_nights'], 'Future day in confirmed stay should show booked room nights.');
        $this->assertSame(0, (int) $futureDay['earned_room_nights']);
        $this->assertSame(0.0, (float) $futureDay['revenue']);

        Carbon::setTestNow();
    }

    public function test_booked_room_nights_sum_matches_summary(): void
    {
        Carbon::setTestNow('2026-06-30');

        $this->artisan('db:seed', ['--class' => ReservationTestDataSeeder::class, '--force' => true, '--no-interaction' => true]);

        $report = app(ReportQueryService::class)->run('revenue-summary', [
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-30',
        ]);

        $rowNights = collect($report['rows'])->sum('room_nights');
        $summaryNights = (int) collect($report['summary'])->firstWhere('label', 'Room nights (booked)')['value'];

        $this->assertSame($summaryNights, (int) $rowNights);

        Carbon::setTestNow();
    }

    public function test_service_output_matches_database_canonical_expectations(): void
    {
        Carbon::setTestNow('2026-06-24 12:00:00');

        $this->artisan('db:seed', [
            '--class' => ReservationTestDataSeeder::class,
            '--force' => true,
            '--no-interaction' => true,
        ]);
        $this->artisan('reservations:backfill-daily-charges', ['--sync-base' => true]);

        $start = Carbon::parse('2026-06-01');
        $end = Carbon::parse('2026-07-31');

        $report = app(ReportQueryService::class)->run('revenue-summary', [
            'start_date' => $start->toDateString(),
            'end_date' => $end->toDateString(),
        ]);

        $this->assertRevenueSummaryReportMatchesCanonicalServices($report, $start, $end);

        Carbon::setTestNow();
    }
}
