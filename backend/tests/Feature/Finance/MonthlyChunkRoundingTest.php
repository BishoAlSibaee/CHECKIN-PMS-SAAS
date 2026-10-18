<?php

namespace Tests\Feature\Finance;

use App\Models\PeakMonth;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\RoomtypePricingplan;
use App\Services\PricingEngine;
use Database\Seeders\ReservationTestDataSeeder;
use Tests\TestCase;

class MonthlyChunkRoundingTest extends TestCase
{
    use \Illuminate\Foundation\Testing\DatabaseTransactions;

    protected function connectionsToTransact(): array
    {
        return ['mysql', 'mysql2'];
    }

    private function resetPricingPlansForRoomType(RoomType $roomType): void
    {
        RoomtypePricingplan::query()->where('roomtype_id', $roomType->id)->delete();
    }

    private function deluxeRoom(int $activeType = 0): Room
    {
        $this->artisan('db:seed', [
            '--class' => ReservationTestDataSeeder::class,
            '--force' => true,
            '--no-interaction' => true,
        ]);

        $roomType = RoomType::query()->where('name_en', 'Deluxe')->firstOrFail();
        $roomType->update([
            'active_type' => $activeType,
            'Min_daily_price' => 100,
            'Max_daily_price' => 150,
            'Min_monthly_price' => 2500,
            'Max_monthly_price' => 3500,
        ]);

        PeakMonth::query()->update(['check' => 0]);

        return Room::query()
            ->where('room_type_id', $roomType->id)
            ->where('active', 1)
            ->firstOrFail();
    }

    public function test_month_end_chunk_sums_to_exact_monthly_rate_without_penny_drift(): void
    {
        $room = $this->deluxeRoom();
        $engine = app(PricingEngine::class);
        $total = $engine->calculateStayBase($room, '2026-07-31', '2026-08-31', 1, 2);

        $this->assertSame(2500.0, $total);
    }

    public function test_last_day_september_to_last_day_october_splits_peak_and_normal_months(): void
    {
        $room = $this->deluxeRoom(activeType: 1);
        PeakMonth::query()->where('month_name_en', 'October')->update(['check' => 1]);

        $engine = app(PricingEngine::class);
        $lines = $engine->buildDailyBreakdown($room, '2026-09-30', '2026-10-31', 1, 2);
        $total = $engine->sumBaseAmount($lines);

        $september = round(collect($lines)->where(fn (array $line) => str_starts_with($line['date'], '2026-09'))->sum('base_amount'), 2);
        $october = round(collect($lines)->where(fn (array $line) => str_starts_with($line['date'], '2026-10'))->sum('base_amount'), 2);

        $this->assertEqualsWithDelta(1 / 30 * 2500, $september, 0.05);
        $this->assertEqualsWithDelta(30 / 31 * 3500, $october, 0.05);
        $this->assertEqualsWithDelta($september + $october, $total, 0.05);
    }

    public function test_last_day_october_to_last_day_november_splits_one_peak_night_and_normal_month(): void
    {
        $room = $this->deluxeRoom(activeType: 1);
        PeakMonth::query()->where('month_name_en', 'October')->update(['check' => 1]);

        $engine = app(PricingEngine::class);
        $lines = $engine->buildDailyBreakdown($room, '2026-10-31', '2026-11-30', 1, 2);
        $total = $engine->sumBaseAmount($lines);

        $october = round(collect($lines)->where(fn (array $line) => str_starts_with($line['date'], '2026-10'))->sum('base_amount'), 2);
        $november = round(collect($lines)->where(fn (array $line) => str_starts_with($line['date'], '2026-11'))->sum('base_amount'), 2);

        $this->assertEqualsWithDelta(1 / 31 * 3500, $october, 0.05);
        $this->assertEqualsWithDelta(29 / 30 * 2500, $november, 0.05);
        $this->assertEqualsWithDelta($october + $november, $total, 0.05);
    }

    public function test_last_day_august_to_last_day_september_splits_peak_and_normal_months(): void
    {
        $room = $this->deluxeRoom(activeType: 1);
        PeakMonth::query()->where('month_name_en', 'August')->update(['check' => 1]);

        $engine = app(PricingEngine::class);
        $total = $engine->calculateStayBase($room, '2026-08-31', '2026-09-30', 1, 2);

        $this->assertEqualsWithDelta((1 / 31 * 3500) + (29 / 30 * 2500), $total, 0.05);
    }

    public function test_last_day_august_to_last_day_september_uses_single_peak_monthly_when_both_months_peak(): void
    {
        $room = $this->deluxeRoom(activeType: 1);
        PeakMonth::query()->whereIn('month_name_en', ['August', 'September'])->update(['check' => 1]);

        $engine = app(PricingEngine::class);
        $lines = $engine->buildDailyBreakdown($room, '2026-08-31', '2026-09-30', 1, 2);
        $total = $engine->sumBaseAmount($lines);

        $this->assertSame(3500.0, $total);
        $this->assertSame(30, count($lines));
        $this->assertEqualsWithDelta(3500 / 30, $total / 30, 0.02);
    }

    public function test_mixed_price_mode_uniform_when_plan_ends_on_first_night_and_both_months_peak(): void
    {
        $room = $this->deluxeRoom(activeType: 1);
        PeakMonth::query()->whereIn('month_name_en', ['August', 'September'])->update(['check' => 1]);

        $roomType = $room->roomType;
        $this->resetPricingPlansForRoomType($roomType);
        $plan = \App\Models\Pricingplan::query()->create([
            'NameAr' => 'أغسطس فقط',
            'NameEn' => 'August Only Mixed Uniform',
            'StartDate' => '2026-08-01',
            'EndDate' => '2026-08-31',
            'ActiveType' => 1,
        ]);

        \App\Models\RoomtypePricingplan::query()->create([
            'roomtype_id' => $roomType->id,
            'pricingplan_id' => $plan->id,
            'DailyPrice' => 150,
            'MonthlyPrice' => 3500,
        ]);

        $engine = app(PricingEngine::class);
        $lines = $engine->buildDailyBreakdown($room, '2026-08-31', '2026-09-30', 1, 0);
        $byDate = collect($lines)->keyBy('date');

        // Aug31 is its own (31-day) calendar month, prorated on the plan's
        // own monthly price independently of September's 29 room-rate nights
        // — even though both months share the same peak rate (3500), a
        // single night in a 31-day month is worth less than 1/30th of it.
        $this->assertEqualsWithDelta(3496.23, $engine->sumBaseAmount($lines), 0.01);
        $this->assertEqualsWithDelta(3500 / 31, (float) $byDate['2026-08-31']['base_amount'], 0.02);
        $this->assertEqualsWithDelta(3500 * (29 / 30) / 29, (float) $byDate['2026-09-01']['base_amount'], 0.02);
        $this->assertSame('plan', $byDate['2026-08-31']['price_source']);
        $this->assertSame('max', $byDate['2026-09-01']['price_source']);
    }

    public function test_september_plan_applies_to_september_nights_when_august_plan_also_exists(): void
    {
        $room = $this->deluxeRoom(activeType: 1);
        PeakMonth::query()->whereIn('month_name_en', ['August', 'September'])->update(['check' => 1]);

        $roomType = $room->roomType;
        $this->resetPricingPlansForRoomType($roomType);

        $augustPlan = \App\Models\Pricingplan::query()->create([
            'NameAr' => 'أغسطس',
            'NameEn' => 'August Plan Per Night',
            'StartDate' => '2026-08-01',
            'EndDate' => '2026-08-31',
            'ActiveType' => 1,
        ]);
        \App\Models\RoomtypePricingplan::query()->create([
            'roomtype_id' => $roomType->id,
            'pricingplan_id' => $augustPlan->id,
            'DailyPrice' => 150,
            'MonthlyPrice' => 3500,
        ]);

        $septemberPlan = \App\Models\Pricingplan::query()->create([
            'NameAr' => 'سبتمبر',
            'NameEn' => 'September Plan Per Night',
            'StartDate' => '2026-09-01',
            'EndDate' => '2026-09-30',
            'ActiveType' => 1,
        ]);
        \App\Models\RoomtypePricingplan::query()->create([
            'roomtype_id' => $roomType->id,
            'pricingplan_id' => $septemberPlan->id,
            'DailyPrice' => 75,
            'MonthlyPrice' => 3200,
        ]);

        $engine = app(PricingEngine::class);
        $lines = $engine->buildDailyBreakdown($room, '2026-08-31', '2026-10-01', 1, 0);
        $byDate = collect($lines)->keyBy('date');

        $this->assertSame('plan', $byDate['2026-08-31']['price_source']);
        $this->assertSame('plan', $byDate['2026-09-15']['price_source']);
        $this->assertSame('plan', $byDate['2026-09-29']['price_source']);
        $this->assertEqualsWithDelta(3200 * (15 / 30) / 15, (float) $byDate['2026-09-15']['base_amount'], 0.02);
        $this->assertLessThan(3500 / 30, (float) $byDate['2026-09-15']['base_amount']);
        $this->assertSame('plan', $byDate['2026-09-30']['price_source']);
        $this->assertSame(75.0, (float) $byDate['2026-09-30']['base_amount']);
    }

    public function test_plan_starting_mid_month_uses_plan_monthly_not_room_rate_in_daily_lines(): void
    {
        $room = $this->deluxeRoom(activeType: 1);
        PeakMonth::query()->whereIn('month_name_en', ['August', 'September', 'October'])->update(['check' => 1]);

        $roomType = $room->roomType;
        $this->resetPricingPlansForRoomType($roomType);
        $plan = \App\Models\Pricingplan::query()->create([
            'NameAr' => 'خطة سبتمبر',
            'NameEn' => 'September Mid Plan',
            'StartDate' => '2026-09-15',
            'EndDate' => '2026-10-31',
            'ActiveType' => 1,
        ]);
        \App\Models\RoomtypePricingplan::query()->create([
            'roomtype_id' => $roomType->id,
            'pricingplan_id' => $plan->id,
            'DailyPrice' => 75,
            'MonthlyPrice' => 2800,
        ]);

        $engine = app(PricingEngine::class);
        $lines = $engine->buildDailyBreakdown($room, '2026-08-31', '2026-10-14', 1, 0);
        $byDate = collect($lines)->keyBy('date');

        $this->assertSame('max', $byDate['2026-09-14']['price_source']);
        $this->assertSame('plan', $byDate['2026-09-15']['price_source']);

        $roomNightsBeforePlan = collect($lines)
            ->filter(fn (array $line) => $line['date'] >= '2026-08-31' && $line['date'] <= '2026-09-14')
            ->pluck('base_amount')
            ->map(fn ($amount) => (float) $amount)
            ->values()
            ->all();
        $this->assertCount(15, $roomNightsBeforePlan);
        $uniqueRates = array_values(array_unique(array_map(
            static fn (float $amount): float => round($amount, 2),
            $roomNightsBeforePlan
        )));
        // Aug31 (1 night, 31-day month) is prorated separately from Sep1-14
        // (14 nights, 30-day month) even though both are room-rate (peak)
        // nights at the same monthly rate — a real calendar-day proration,
        // not a flat split across the whole 15-night room-rate stretch.
        $this->assertSame([112.9, 116.67, 116.66], $uniqueRates);
        $this->assertEqualsWithDelta(
            1746.23,
            round(array_sum($roomNightsBeforePlan), 2),
            0.01
        );

        $this->assertEqualsWithDelta(2800 * (15 / 30) / 15, (float) $byDate['2026-09-15']['base_amount'], 0.05);
        $this->assertLessThan((float) $byDate['2026-09-14']['base_amount'], (float) $byDate['2026-09-15']['base_amount']);
        $this->assertSame(75.0, (float) $byDate['2026-10-13']['base_amount']);
    }

    public function test_price_segments_split_plan_monthly_proration_from_plan_daily_extras(): void
    {
        $room = $this->deluxeRoom(activeType: 1);
        PeakMonth::query()->whereIn('month_name_en', ['August', 'September', 'October'])->update(['check' => 1]);

        $roomType = $room->roomType;
        $this->resetPricingPlansForRoomType($roomType);
        $plan = \App\Models\Pricingplan::query()->create([
            'NameAr' => 'خطة سبتمبر',
            'NameEn' => 'September Mid Plan',
            'StartDate' => '2026-09-15',
            'EndDate' => '2026-10-31',
            'ActiveType' => 1,
        ]);
        \App\Models\RoomtypePricingplan::query()->create([
            'roomtype_id' => $roomType->id,
            'pricingplan_id' => $plan->id,
            'DailyPrice' => 75,
            'MonthlyPrice' => 2800,
        ]);

        $engine = app(PricingEngine::class);
        $lines = $engine->buildDailyBreakdown($room, '2026-08-31', '2026-10-14', 1, 0);
        $segments = $engine->buildPriceSegments($lines, 1);
        $planSegments = collect($segments)->where('billing_source', 'plan')->values();

        $this->assertGreaterThanOrEqual(2, $planSegments->count());
        $rates = $planSegments->map(
            fn (array $segment) => round($segment['amount'] / max(1, $segment['nights']), 2)
        )->unique()->values()->all();
        $this->assertContains(75.0, $rates);
        $this->assertTrue(collect($rates)->contains(fn (float $rate) => $rate > 80.0));
    }

    public function test_last_day_august_to_last_day_september_uses_single_normal_monthly_when_both_months_normal(): void
    {
        $room = $this->deluxeRoom(activeType: 1);
        PeakMonth::query()->update(['check' => 0]);

        $engine = app(PricingEngine::class);
        $lines = $engine->buildDailyBreakdown($room, '2026-08-31', '2026-09-30', 1, 2);
        $total = $engine->sumBaseAmount($lines);

        $this->assertSame(2500.0, $total);
        $this->assertSame(30, count($lines));
        $this->assertEqualsWithDelta(2500 / 30, $total / 30, 0.02);
    }

    public function test_extra_days_after_first_full_month_are_billed_daily(): void
    {
        $room = $this->deluxeRoom(activeType: 1);
        PeakMonth::query()->where('month_name_en', 'October')->update(['check' => 1]);

        $engine = app(PricingEngine::class);
        $lines = $engine->buildDailyBreakdown($room, '2026-09-30', '2026-11-03', 1, 2);
        $total = $engine->sumBaseAmount($lines);

        $monthlyPeriodLines = collect($lines)->filter(
            fn (array $line) => $line['date'] >= '2026-09-30' && $line['date'] <= '2026-10-30'
        );
        $extraLines = collect($lines)->filter(fn (array $line) => $line['date'] >= '2026-10-31');

        $this->assertSame(31, $monthlyPeriodLines->count());
        $this->assertSame(3, $extraLines->count());
        $this->assertEqualsWithDelta(3 * 100, round($extraLines->sum('base_amount'), 2), 0.05);
        $this->assertEqualsWithDelta(
            round($monthlyPeriodLines->sum('base_amount'), 2) + round($extraLines->sum('base_amount'), 2),
            $total,
            0.05
        );
    }
}
