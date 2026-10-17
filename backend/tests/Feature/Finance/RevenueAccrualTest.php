<?php

namespace Tests\Feature\Finance;

use App\Models\Reservation;
use App\Models\ReservationDailyCharge;
use App\Services\RevenueAccrualService;
use Carbon\Carbon;
use Database\Seeders\ReservationTestDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RevenueAccrualTest extends TestCase
{
    public function test_august_revenue_uses_daily_charges_not_flat_rate(): void
    {
        Carbon::setTestNow('2026-08-31');

        $this->artisan('db:seed', ['--class' => ReservationTestDataSeeder::class, '--force' => true, '--no-interaction' => true]);

        $service = app(RevenueAccrualService::class);
        $aug = $service->calculate('total', null, Carbon::parse('2026-08-01'), Carbon::parse('2026-08-31'), true);

        $this->assertGreaterThan(0, $aug['current']['count']);
        $this->assertGreaterThan(0, $aug['current']['total']);

        $booking6 = Reservation::where('start_date', '2026-07-26')->first();
        $this->assertNotNull($booking6);

        $augNights = ReservationDailyCharge::where('reservation_id', $booking6->id)
            ->whereBetween('charge_date', ['2026-08-01', '2026-08-31'])
            ->count();

        $this->assertEquals(8, $augNights);
        $this->assertEquals(
            round($aug['current']['total_base'], 2),
            round(ReservationDailyCharge::whereBetween('charge_date', ['2026-08-01', '2026-08-31'])
                ->whereHas('reservation', fn ($q) => $q->where('reservation_status', 1))
                ->sum('base_amount'), 2)
        );

        Carbon::setTestNow();
    }

    public function test_future_period_accrual_is_zero_before_stay_nights(): void
    {
        $this->artisan('db:seed', ['--class' => ReservationTestDataSeeder::class, '--force' => true, '--no-interaction' => true]);

        Carbon::setTestNow('2026-06-21');

        $service = app(RevenueAccrualService::class);
        $aug = $service->calculate('total', null, Carbon::parse('2026-08-01'), Carbon::parse('2026-08-31'), false);

        $this->assertSame(0.0, (float) $aug['current']['total']);
        $this->assertSame(0, (int) $aug['current']['count']);

        Carbon::setTestNow();
    }

    public function test_future_period_cash_is_zero_before_payment_dates(): void
    {
        $this->artisan('db:seed', ['--class' => ReservationTestDataSeeder::class, '--force' => true, '--no-interaction' => true]);

        Carbon::setTestNow('2026-06-21');

        $service = app(\App\Services\Reports\ReportQueryService::class);
        $report = $service->run('overview', [
            'start_date' => '2026-07-01',
            'end_date' => '2026-07-31',
        ]);

        $rows = collect($report['rows'] ?? [])->keyBy('metric');
        $this->assertEqualsWithDelta(0.0, (float) ($rows['Cash in']['amount'] ?? -1), 0.01);
        $this->assertEqualsWithDelta(0.0, (float) ($rows['Net cash']['amount'] ?? -1), 0.01);

        Carbon::setTestNow();
    }

    public function test_status_two_excluded_from_revenue(): void
    {
        Carbon::setTestNow('2026-06-30');

        $this->artisan('db:seed', ['--class' => ReservationTestDataSeeder::class, '--force' => true, '--no-interaction' => true]);

        $service = app(RevenueAccrualService::class);
        $jun = $service->calculate('total', null, Carbon::parse('2026-06-01'), Carbon::parse('2026-06-30'), true);

        $status2Ids = Reservation::where('reservation_status', 2)->pluck('id');
        $detailIds = collect($jun['details'] ?? [])->pluck('reservation_id')->unique();

        $this->assertTrue($detailIds->intersect($status2Ids)->isEmpty());

        Carbon::setTestNow();
    }

    public function test_completed_stay_accrual_matches_reservation_total(): void
    {
        Carbon::setTestNow('2026-07-10');

        $this->artisan('db:seed', ['--class' => ReservationTestDataSeeder::class, '--force' => true, '--no-interaction' => true]);

        $reservation = Reservation::where('start_date', '2026-06-19')->where('expire_date', '2026-07-04')->first();
        $this->assertNotNull($reservation);

        $service = app(RevenueAccrualService::class);
        $accrual = $service->calculate(
            'total',
            null,
            Carbon::parse($reservation->start_date),
            Carbon::parse($reservation->expire_date),
            true
        );

        $lines = collect($accrual['details'] ?? [])->where('reservation_id', $reservation->id);
        $this->assertSame(
            (int) ReservationDailyCharge::where('reservation_id', $reservation->id)->count(),
            $lines->count()
        );
        $this->assertEqualsWithDelta(
            (float) $reservation->total,
            round($lines->sum('revenue'), 2),
            0.05,
            'After checkout, accrual lines for the stay should sum to reservation total.'
        );

        Carbon::setTestNow();
    }

    public function test_in_progress_stay_accrual_is_less_than_reservation_total(): void
    {
        Carbon::setTestNow('2026-06-24');

        $this->artisan('db:seed', ['--class' => ReservationTestDataSeeder::class, '--force' => true, '--no-interaction' => true]);

        $reservation = Reservation::where('start_date', '2026-06-19')->where('expire_date', '2026-07-04')->first();
        $this->assertNotNull($reservation);

        $service = app(RevenueAccrualService::class);
        $accrual = $service->calculate(
            'total',
            null,
            Carbon::parse('2026-06-01'),
            Carbon::parse('2026-12-31'),
            true
        );

        $lines = collect($accrual['details'] ?? [])->where('reservation_id', $reservation->id);
        $this->assertGreaterThan(0, $lines->count());
        $this->assertLessThan((float) $reservation->total, round($lines->sum('revenue'), 2));

        Carbon::setTestNow();
    }

    public function test_accrual_details_include_guest_names_from_client_database(): void
    {
        Carbon::setTestNow('2026-06-24');

        $this->artisan('db:seed', ['--class' => ReservationTestDataSeeder::class, '--force' => true, '--no-interaction' => true]);

        $service = app(RevenueAccrualService::class);
        $accrual = $service->calculate(
            'total',
            null,
            Carbon::parse('2026-06-01'),
            Carbon::parse('2026-06-30'),
            true
        );

        $named = collect($accrual['details'] ?? [])
            ->filter(fn ($line) => trim((string) ($line['guest'] ?? '')) !== '' && ($line['guest'] ?? '') !== '—');

        $this->assertGreaterThan(0, $named->count(), 'Accrual detail lines must include guest names from mysql2 clients.');

        Carbon::setTestNow();
    }

    public function test_reservation_base_matches_sum_of_daily_charges(): void
    {
        $this->artisan('db:seed', ['--class' => ReservationTestDataSeeder::class, '--force' => true, '--no-interaction' => true]);

        foreach (Reservation::whereYear('start_date', 2026)->get() as $reservation) {
            $sum = (float) ReservationDailyCharge::where('reservation_id', $reservation->id)->sum('base_amount');
            $this->assertEqualsWithDelta(
                (float) $reservation->base_price,
                $sum,
                0.02,
                "Reservation {$reservation->id} base mismatch"
            );
        }
    }
}
