<?php

namespace Tests\Feature\Reservation;

use App\Models\Pricingplan;
use App\Models\Room;
use App\Models\RoomtypePricingplan;
use App\Services\PricingEngine;
use Tests\Support\InteractsWithTestRoomTypes;
use Tests\TestCase;

class MixedPricingApiTest extends TestCase
{
    use InteractsWithTestRoomTypes;

    protected function tearDown(): void
    {
        $this->tearDownTestRoomTypes();
        parent::tearDown();
    }

    private function authUser(): \App\Models\User
    {
        return $this->userWithApiPermissions(['create reservations', 'view reservations']);
    }

    /**
     * Client scenario: stay Aug 1–11 checkout, promo plan from Aug 5, mixed mode.
     */
    public function test_get_room_price_mixed_splits_before_and_inside_promo_plan(): void
    {
        $user = $this->authUser();

        $roomType = $this->createTestRoomType([
            'name_ar' => 'اختبار ميكس',
            'name_en' => 'Mixed API Test ' . uniqid(),
            'description' => 'API mixed pricing test',
            'Min_daily_price' => 100,
            'Max_daily_price' => 200,
            'Min_monthly_price' => 2400,
            'Max_monthly_price' => 4800,
        ]);

        $longPlan = Pricingplan::create([
            'NameAr' => 'Base Long',
            'NameEn' => 'Base Long ' . uniqid(),
            'StartDate' => '2026-01-01',
            'EndDate' => '2026-12-31',
            'ActiveType' => 1,
        ]);
        RoomtypePricingplan::create([
            'roomtype_id' => $roomType->id,
            'pricingplan_id' => $longPlan->id,
            'DailyPrice' => 280,
            'MonthlyPrice' => 6000,
        ]);

        $promoPlan = Pricingplan::create([
            'NameAr' => 'عرض أغسطس',
            'NameEn' => 'August Promo ' . uniqid(),
            'StartDate' => '2026-08-05',
            'EndDate' => '2026-08-31',
            'ActiveType' => 1,
        ]);
        RoomtypePricingplan::create([
            'roomtype_id' => $roomType->id,
            'pricingplan_id' => $promoPlan->id,
            'DailyPrice' => 150,
            'MonthlyPrice' => 3500,
        ]);

        $building = \App\Models\Building::first();
        $floor = \App\Models\Floor::first();
        $this->assertNotNull($building);
        $this->assertNotNull($floor);

        $room = Room::where('active', 1)->first();
        $origType = $room->room_type_id;
        $room->room_type_id = $roomType->id;
        $room->save();

        try {
            $response = $this->actingAs($user, 'api')->postJson('/api/users/getRoomPrice', [
                'startDate' => '2026-08-01',
                'endDate' => '2026-08-11',
                'roomTypeId' => $roomType->id,
                'typeReservation' => 0,
                'price_calculation_mode' => 0,
            ]);

            $response->assertOk();
            $response->assertJsonPath('success', true);

            $days = $response->json('data.days');
            $this->assertIsArray($days);
            $this->assertCount(10, $days);

            $this->assertEqualsWithDelta(100.0, (float) $days['2026-08-01'], 0.01);
            $this->assertEqualsWithDelta(100.0, (float) $days['2026-08-04'], 0.01);
            $this->assertEqualsWithDelta(150.0, (float) $days['2026-08-05'], 0.01);
            $this->assertEqualsWithDelta(150.0, (float) $days['2026-08-10'], 0.01);
            $this->assertEqualsWithDelta(1300.0, (float) $response->json('data.totalPrice'), 0.02);

            $segments = $response->json('data.segments');
            $sources = array_column($segments, 'billing_source');
            $this->assertContains('plan', $sources);
            $this->assertTrue(count(array_intersect($sources, ['min', 'max'])) > 0);

            $engine = app(PricingEngine::class);
            $expected = $engine->calculateStayBase($room, '2026-08-01', '2026-08-11', 0, 0);
            $this->assertEqualsWithDelta($expected, (float) $response->json('data.totalPrice'), 0.02);
        } finally {
            $room->room_type_id = $origType;
            $room->save();
        }
    }

    public function test_mixed_mode_all_room_type_when_plan_starts_after_stay_end(): void
    {
        $user = $this->authUser();

        $roomType = $this->createTestRoomType([
            'name_ar' => 'بدون خطة متقاطعة',
            'name_en' => 'No Overlap Plan ' . uniqid(),
            'description' => 'Plan starts after stay',
            'Min_daily_price' => 120,
            'Max_daily_price' => 180,
            'Min_monthly_price' => 3000,
            'Max_monthly_price' => 4500,
        ]);

        $latePlan = Pricingplan::create([
            'NameAr' => 'خطة متأخرة',
            'NameEn' => 'Late Plan ' . uniqid(),
            'StartDate' => '2026-08-15',
            'EndDate' => '2026-12-31',
            'ActiveType' => 1,
        ]);
        RoomtypePricingplan::create([
            'roomtype_id' => $roomType->id,
            'pricingplan_id' => $latePlan->id,
            'DailyPrice' => 999,
            'MonthlyPrice' => 9999,
        ]);

        $room = Room::where('active', 1)->first();
        $this->assertNotNull($room);
        $origType = $room->room_type_id;
        $room->room_type_id = $roomType->id;
        $room->save();

        try {
            $response = $this->actingAs($user, 'api')->postJson('/api/users/getRoomPrice', [
                'startDate' => '2026-08-01',
                'endDate' => '2026-08-11',
                'roomTypeId' => $roomType->id,
                'typeReservation' => 0,
                'price_calculation_mode' => 0,
            ]);

            $response->assertOk();
            $days = $response->json('data.days') ?? [];
            foreach ($days as $date => $amount) {
                $this->assertGreaterThan(0, (float) $amount, "Night {$date} should have room-type pricing");
            }

            $segments = $response->json('data.segments') ?? [];
            foreach ($segments as $seg) {
                $this->assertNotSame('plan', $seg['billing_source'] ?? null, 'No plan segment when plan starts after stay');
            }
        } finally {
            $room->room_type_id = $origType;
            $room->save();
        }
    }
}
