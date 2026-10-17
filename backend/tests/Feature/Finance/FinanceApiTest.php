<?php

namespace Tests\Feature\Finance;

use App\Models\Room;
use App\Models\RoomType;
use App\Services\PricingEngine;
use App\Services\RevenueAccrualService;
use Carbon\Carbon;
use Database\Seeders\ReservationTestDataSeeder;
use Tests\Support\FinanceAssertions;
use Tests\TestCase;

class FinanceApiTest extends TestCase
{
    use FinanceAssertions;

    private function seedAndUser(): \App\Models\User
    {
        $this->artisan('db:seed', [
            '--class' => ReservationTestDataSeeder::class,
            '--force' => true,
            '--no-interaction' => true,
        ]);

        return $this->userWithApiPermissions([
            'view revenue', 'view earnings', 'view reports', 'view financial reports',
        ]);
    }

    public function test_get_room_price_matrix_daily_and_monthly(): void
    {
        $user = $this->seedAndUser();
        $roomType = RoomType::where('name_en', 'Deluxe')->first();
        $this->assertNotNull($roomType);

        $engine = app(PricingEngine::class);
        $room = Room::where('room_type_id', $roomType->id)->where('active', 1)->first();
        $this->assertNotNull($room);

        $cases = [
            ['start' => '2026-08-01', 'end' => '2026-08-08', 'type' => 0, 'mode' => 0],
            ['start' => '2026-08-01', 'end' => '2026-08-08', 'type' => 0, 'mode' => 1],
            ['start' => '2026-08-01', 'end' => '2026-08-08', 'type' => 0, 'mode' => 2],
            ['start' => '2026-06-03', 'end' => '2026-07-03', 'type' => 1, 'mode' => 0],
            ['start' => '2026-06-03', 'end' => '2026-07-03', 'type' => 1, 'mode' => 1],
            ['start' => '2026-06-03', 'end' => '2026-07-03', 'type' => 1, 'mode' => 2],
        ];

        foreach ($cases as $case) {
            $expected = $engine->calculateStayBase(
                $room,
                $case['start'],
                $case['end'],
                $case['type'],
                $case['mode']
            );

            $response = $this->actingAs($user, 'api')->postJson('/api/users/getRoomPrice', [
                'startDate' => $case['start'],
                'endDate' => $case['end'],
                'roomTypeId' => $roomType->id,
                'typeReservation' => $case['type'],
                'price_calculation_mode' => $case['mode'],
            ]);

            $response->assertOk();
            $body = $response->json();
            $this->assertTrue($body['success'] ?? false, json_encode($case));

            $apiTotal = (float) ($body['data']['totalPrice'] ?? 0);
            $daysSum = array_sum($body['data']['days'] ?? []);

            $this->assertEqualsWithDelta($expected, $apiTotal, 0.05, "totalPrice case " . json_encode($case));
            $this->assertEqualsWithDelta($expected, $daysSum, 0.05, "days sum case " . json_encode($case));
        }
    }

    public function test_dashboard_summary_accrual_matches_service(): void
    {
        $user = $this->seedAndUser();

        $response = $this->actingAs($user, 'api')->getJson('/api/users/dashboard/summary');
        $response->assertOk();
        $response->assertJsonPath('success', true);

        $today = Carbon::today();
        $expected = app(RevenueAccrualService::class)->calculate(
            'total',
            null,
            $today->copy()->startOfMonth(),
            $today->copy()->endOfMonth(),
            false
        );

        $apiAccrual = (float) $response->json('data.month_accrual_revenue');
        $this->assertEqualsWithDelta(
            round((float) $expected['current']['total'], 2),
            $apiAccrual,
            0.05
        );
    }

    public function test_revenue_total_api_matches_accrual_service(): void
    {
        $user = $this->seedAndUser();
        $start = '2026-08-01';
        $end = '2026-08-31';

        $expected = app(RevenueAccrualService::class)->calculate(
            'total',
            null,
            Carbon::parse($start),
            Carbon::parse($end),
            false
        );

        $response = $this->actingAs($user, 'api')->getJson(
            "/api/users/revenue/total?start_date={$start}&end_date={$end}"
        );

        $response->assertOk();
        $response->assertJsonPath('success', true);

        $apiCurrent = $response->json('data.revenue.current') ?? $response->json('data.current');
        $this->assertNotNull($apiCurrent);
        $this->assertEqualsWithDelta(
            round((float) $expected['current']['total'], 2),
            round((float) ($apiCurrent['total'] ?? 0), 2),
            0.05
        );
        $this->assertEqualsWithDelta(
            round((float) $expected['current']['total_base'], 2),
            round((float) ($apiCurrent['total_base'] ?? 0), 2),
            0.05
        );
    }

    public function test_daily_rent_price_segments_use_daily_kind(): void
    {
        $user = $this->seedAndUser();
        $roomType = RoomType::where('name_en', 'Deluxe')->first();

        $response = $this->actingAs($user, 'api')->postJson('/api/users/getRoomPrice', [
            'startDate' => '2026-08-01',
            'endDate' => '2026-08-08',
            'roomTypeId' => $roomType->id,
            'typeReservation' => 0,
            'price_calculation_mode' => 0,
        ]);

        $response->assertOk();
        $segments = $response->json('data.segments') ?? [];
        $this->assertNotEmpty($segments);
        foreach ($segments as $segment) {
            $this->assertSame('daily', $segment['kind'] ?? null);
        }
    }

    public function test_mixed_monthly_segments_include_plan_and_room_sources(): void
    {
        $user = $this->seedAndUser();
        $roomType = RoomType::where('name_en', 'Deluxe')->first();

        $response = $this->actingAs($user, 'api')->postJson('/api/users/getRoomPrice', [
            'startDate' => '2026-06-03',
            'endDate' => '2026-07-03',
            'roomTypeId' => $roomType->id,
            'typeReservation' => 1,
            'price_calculation_mode' => 0,
        ]);

        $response->assertOk();
        $segments = $response->json('data.segments') ?? [];
        $this->assertNotEmpty($segments);

        $sources = collect($segments)->pluck('billing_source')->filter()->unique()->values()->all();
        $this->assertContains('plan', $sources);
        $this->assertTrue(
            count(array_intersect($sources, ['min', 'max', 'monthly'])) > 0,
            'Expected room-type monthly segment source'
        );
    }
}
