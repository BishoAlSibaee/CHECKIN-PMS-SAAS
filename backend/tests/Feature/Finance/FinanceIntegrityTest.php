<?php

namespace Tests\Feature\Finance;

use App\Models\Reservation;
use App\Models\ReservationDailyCharge;
use App\Services\PricingEngine;
use App\Services\RevenueAccrualService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Artisan;
use Tests\Support\FinanceAssertions;
use Tests\Support\FinanceTestBootstrap;
use Tests\TestCase;

class FinanceIntegrityTest extends TestCase
{
    use FinanceAssertions;
    use FinanceTestBootstrap;

    public function test_finance_audit_command_passes_after_seed(): void
    {
        $this->bootstrapFinanceData();

        $exit = Artisan::call('finance:audit');
        $this->assertSame(0, $exit, Artisan::output());
    }

    public function test_all_confirmed_reservations_satisfy_financial_contract(): void
    {
        $this->bootstrapFinanceData();

        $confirmed = Reservation::where('reservation_status', 1)
            ->whereHas('reservationRooms')
            ->get();

        $this->assertGreaterThan(0, $confirmed->count());

        foreach ($confirmed as $reservation) {
            $chargeCount = ReservationDailyCharge::where('reservation_id', $reservation->id)->count();
            $this->assertGreaterThan(0, $chargeCount, "Reservation {$reservation->id} has no daily charges");

            $this->assertReservationFinancialContract($reservation, "reservation #{$reservation->id}");
        }
    }

    public function test_repricing_engine_matches_stored_charges_for_2026(): void
    {
        $this->bootstrapFinanceData();

        $engine = app(PricingEngine::class);

        $reservations = Reservation::whereYear('start_date', 2026)
            ->with('reservationRooms.room')
            ->get();

        foreach ($reservations as $reservation) {
            $this->assertPricingEngineMatchesReservation($engine, $reservation);
        }
    }

    public function test_accrual_revenue_equals_sum_of_allocated_charge_revenue(): void
    {
        $this->bootstrapFinanceData();

        $start = Carbon::parse(self::FINANCE_PERIOD_START);
        $end = Carbon::parse(self::FINANCE_PERIOD_END);
        $service = app(RevenueAccrualService::class);
        $accrual = $service->calculate('total', null, $start, $end, true);

        $detailRevenue = round(collect($accrual['details'] ?? [])->sum('revenue'), 2);
        $this->assertEqualsWithDelta(
            round((float) $accrual['current']['total'], 2),
            $detailRevenue,
            0.10,
            'Accrual header total must match sum of detail revenue lines (rounding tolerance)'
        );

        $detailBase = round(collect($accrual['details'] ?? [])->sum('base_amount'), 2);
        $this->assertEqualsWithDelta(
            round((float) $accrual['current']['total_base'], 2),
            $detailBase,
            0.05
        );
    }

    public function test_cancelled_reservations_excluded_from_accrual_details(): void
    {
        $this->bootstrapFinanceData();

        $service = app(RevenueAccrualService::class);
        $accrual = $service->calculate('total', null, Carbon::parse('2026-06-01'), Carbon::parse('2026-09-30'), true);

        $cancelledIds = Reservation::where('reservation_status', 2)->pluck('id');
        $detailIds = collect($accrual['details'] ?? [])->pluck('reservation_id')->unique();

        $this->assertTrue($detailIds->intersect($cancelledIds)->isEmpty());
    }
}
