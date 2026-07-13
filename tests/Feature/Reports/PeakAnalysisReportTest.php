<?php

namespace Tests\Feature\Reports;

use App\Models\PeakDay;
use App\Services\Reports\ReportQueryService;
use App\Services\RevenueAccrualService;
use Carbon\Carbon;
use Database\Seeders\ReservationTestDataSeeder;
use Tests\TestCase;

class PeakAnalysisReportTest extends TestCase
{
    public function test_peak_analysis_includes_future_peak_nights_in_selected_period(): void
    {
        Carbon::setTestNow('2026-07-12');

        $this->artisan('db:seed', [
            '--class' => ReservationTestDataSeeder::class,
            '--force' => true,
            '--no-interaction' => true,
        ]);

        PeakDay::whereIn('day_name_en', ['Wednesday', 'Thursday', 'Friday'])->update(['check' => 1]);

        $report = app(ReportQueryService::class)->run('peak-analysis', [
            'start_date' => '2026-07-01',
            'end_date' => '2026-07-31',
        ]);

        $rows = collect($report['rows'] ?? [])->keyBy('segment');
        $peakNights = (int) ($rows['Peak days']['room_nights'] ?? 0);

        $this->assertGreaterThan(
            0,
            $peakNights,
            'Booked peak nights later in July must appear when analyzing the full selected period.'
        );

        Carbon::setTestNow();
    }

    public function test_revenue_accrual_still_caps_recognized_nights_at_today_by_default(): void
    {
        Carbon::setTestNow('2026-07-12');

        $this->artisan('db:seed', [
            '--class' => ReservationTestDataSeeder::class,
            '--force' => true,
            '--no-interaction' => true,
        ]);

        $service = app(RevenueAccrualService::class);
        $capped = $service->calculate('total', null, Carbon::parse('2026-07-01'), Carbon::parse('2026-07-31'), true);
        $fullPeriod = $service->calculate('total', null, Carbon::parse('2026-07-01'), Carbon::parse('2026-07-31'), true, false);

        $this->assertLessThan(
            (int) $fullPeriod['current']['count'],
            (int) $capped['current']['count']
        );
        $this->assertSame(
            '2026-07-12',
            collect($capped['details'] ?? [])->max('charge_date')
        );

        Carbon::setTestNow();
    }
}
