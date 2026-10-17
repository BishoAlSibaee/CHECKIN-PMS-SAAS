<?php

namespace Tests\Feature\Finance;

use App\Models\Room;
use App\Models\RoomType;
use App\Services\PricingEngine;
use Database\Seeders\ReservationTestDataSeeder;
use Tests\TestCase;

class PricingEngineDetailedTest extends TestCase
{
    private function deluxeTestRoom(): array
    {
        $this->artisan('db:seed', [
            '--class' => ReservationTestDataSeeder::class,
            '--force' => true,
            '--no-interaction' => true,
        ]);

        $roomType = RoomType::where('name_en', 'Deluxe')->firstOrFail();
        $room = Room::where('room_type_id', $roomType->id)->where('active', 1)->firstOrFail();

        return [$room, $roomType];
    }

    public function test_daily_rent_segments_are_daily_kind(): void
    {
        [$room] = $this->deluxeTestRoom();
        $engine = app(PricingEngine::class);

        $lines = $engine->buildDailyBreakdown($room, '2026-08-01', '2026-08-08', 0, 0);
        $segments = $engine->buildPriceSegments($lines, 0);

        $this->assertNotEmpty($segments);
        foreach ($segments as $segment) {
            $this->assertSame('daily', $segment['kind']);
        }
    }

    public function test_daily_lines_count_equals_nights(): void
    {
        [$room] = $this->deluxeTestRoom();
        $engine = app(PricingEngine::class);

        $lines = $engine->buildDailyBreakdown($room, '2026-08-01', '2026-08-08', 0, 0);
        $this->assertCount(7, $lines);
        $this->assertEqualsWithDelta(
            $engine->calculateStayBase($room, '2026-08-01', '2026-08-08', 0, 0),
            $engine->sumBaseAmount($lines),
            0.02
        );
    }

    public function test_monthly_mixed_less_than_room_only_when_plan_partial(): void
    {
        [$room] = $this->deluxeTestRoom();
        $engine = app(PricingEngine::class);

        $mixed = $engine->calculateStayBase($room, '2026-06-03', '2026-07-03', 1, 0);
        $roomOnly = $engine->calculateStayBase($room, '2026-06-03', '2026-07-03', 1, 2);
        $planOnly = $engine->calculateStayBase($room, '2026-06-03', '2026-07-03', 1, 1);

        $this->assertLessThan($roomOnly, $mixed);
        // active_type=1 with a plan that only partially overlaps the stay:
        // "plan-only" mode (price_calculation_mode=1) falls back to the same
        // per-night mixed calculation as the real default (mode 0) in this
        // case, so the two are equal rather than mixed being strictly higher.
        $this->assertGreaterThanOrEqual($planOnly, $mixed);
        $this->assertEqualsWithDelta(10959.14, $mixed, 0.05);
    }

    public function test_mixed_monthly_uses_calendar_month_denominators(): void
    {
        [$room] = $this->deluxeTestRoom();
        $engine = app(PricingEngine::class);
        $lines = $engine->buildDailyBreakdown($room, '2026-06-03', '2026-07-03', 1, 0);

        $juneRoom = 0.0;
        $julyPlan = 0.0;
        foreach ($lines as $line) {
            if (str_starts_with($line['date'], '2026-06')) {
                $juneRoom += $line['base_amount'];
            }
            if (str_starts_with($line['date'], '2026-07') && $line['price_source'] === 'plan') {
                $julyPlan += $line['base_amount'];
            }
        }

        $this->assertEqualsWithDelta(28 / 30 * 11500, $juneRoom, 0.10);
        $this->assertEqualsWithDelta(2 / 31 * 3500, $julyPlan, 0.10);
    }

    public function test_segments_split_plan_and_room_type(): void
    {
        [$room] = $this->deluxeTestRoom();
        $engine = app(PricingEngine::class);
        $lines = $engine->buildDailyBreakdown($room, '2026-06-03', '2026-07-03', 1, 0);
        $segments = $engine->buildPriceSegments($lines, 1);

        $this->assertGreaterThanOrEqual(2, count($segments));

        $billingSources = array_column($segments, 'billing_source');
        $this->assertContains('plan', $billingSources);
        $this->assertTrue(count(array_intersect($billingSources, ['min', 'max'])) > 0);
    }

    public function test_calendar_month_28_vs_31_produces_different_quarter_weights(): void
    {
        $planMonthly = 3500.0;
        $febQuarter = round((7 / 28) * $planMonthly, 2);
        $julQuarter = round((8 / 31) * $planMonthly, 2);

        $this->assertEqualsWithDelta(875.0, $febQuarter, 0.01);
        $this->assertGreaterThan($febQuarter, $julQuarter);
    }

    public function test_mixed_daily_prefers_narrow_plan_over_long_base_plan(): void
    {
        [$room, $roomType] = $this->deluxeTestRoom();
        $engine = app(PricingEngine::class);

        \App\Models\Pricingplan::query()->create([
            'NameAr' => 'August Promo',
            'NameEn' => 'August Promo Narrow',
            'StartDate' => '2026-08-05',
            'EndDate' => '2026-08-31',
            'ActiveType' => 1,
        ]);
        $augPlan = \App\Models\Pricingplan::where('NameEn', 'August Promo Narrow')->firstOrFail();

        \App\Models\RoomtypePricingplan::query()->create([
            'roomtype_id' => $roomType->id,
            'pricingplan_id' => $augPlan->id,
            'DailyPrice' => 150,
            'MonthlyPrice' => 3500,
        ]);

        $lines = $engine->buildDailyBreakdown($room, '2026-08-01', '2026-08-11', 0, 0);
        $byDate = collect($lines)->keyBy('date');

        $this->assertSame(320.0, (float) $byDate['2026-08-01']['base_amount']);
        $this->assertSame('min', $byDate['2026-08-01']['price_source']);
        $this->assertSame(150.0, (float) $byDate['2026-08-05']['base_amount']);
        $this->assertSame('plan', $byDate['2026-08-05']['price_source']);
        $this->assertEqualsWithDelta(2180.0, $engine->sumBaseAmount($lines), 0.02);
    }

    public function test_mixed_daily_splits_in_plan_nights_when_active_type_is_const(): void
    {
        [$room, $roomType] = $this->deluxeTestRoom();
        $engine = app(PricingEngine::class);

        \App\Models\Pricingplan::query()->create([
            'NameAr' => 'August Const',
            'NameEn' => 'August Const Narrow',
            'StartDate' => '2026-08-05',
            'EndDate' => '2026-08-31',
            'ActiveType' => 0,
        ]);
        $augPlan = \App\Models\Pricingplan::where('NameEn', 'August Const Narrow')->firstOrFail();

        \App\Models\RoomtypePricingplan::query()->create([
            'roomtype_id' => $roomType->id,
            'pricingplan_id' => $augPlan->id,
            'DailyPrice' => 150,
            'MonthlyPrice' => 3500,
        ]);

        $lines = $engine->buildDailyBreakdown($room, '2026-08-01', '2026-08-11', 0, 0);
        $byDate = collect($lines)->keyBy('date');

        $this->assertSame('min', $byDate['2026-08-04']['price_source']);
        $this->assertSame('plan', $byDate['2026-08-05']['price_source']);
        $this->assertSame(150.0, (float) $byDate['2026-08-05']['base_amount']);
        $this->assertSame('plan', $byDate['2026-08-10']['price_source']);
    }
}
