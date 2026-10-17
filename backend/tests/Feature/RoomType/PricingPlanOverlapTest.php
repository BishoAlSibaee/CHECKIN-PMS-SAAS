<?php

namespace Tests\Feature\RoomType;

use App\Models\RoomType;
use Tests\Support\InteractsWithTestRoomTypes;
use Tests\TestCase;

class PricingPlanOverlapTest extends TestCase
{
    use InteractsWithTestRoomTypes;

    protected function tearDown(): void
    {
        $this->tearDownTestRoomTypes();
        parent::tearDown();
    }

    private function pricingUser(): \App\Models\User
    {
        return $this->userWithApiPermissions(['manage room types', 'manage pricing plans']);
    }

    private function roomTypeWithoutPricing(): RoomType
    {
        return $this->createTestRoomType([
            'name_ar' => 'نوع اختبار التسعير',
            'name_en' => 'Pricing Overlap Test ' . uniqid(),
            'description' => 'Isolated room type for overlap tests',
            'Max_daily_price' => 500,
            'Min_daily_price' => 50,
            'Max_monthly_price' => 10000,
            'Min_monthly_price' => 1000,
        ]);
    }

    public function test_rejects_overlapping_pricing_plans_for_same_room_type(): void
    {
        $user = $this->pricingUser();
        $roomType = $this->roomTypeWithoutPricing();

        $first = $this->actingAs($user, 'api')->postJson('/api/users/addRoomtypePricing', [
            'roomtype_id' => $roomType->id,
            'NameAr' => 'خطة أ',
            'NameEn' => 'Plan A',
            'StartDate' => '2030-06-01',
            'EndDate' => '2030-06-30',
            'ActiveType' => 1,
            'DailyPrice' => 100,
            'MonthlyPrice' => 2500,
        ]);
        $first->assertOk();
        $first->assertJsonPath('success', true);

        $overlap = $this->actingAs($user, 'api')->postJson('/api/users/addRoomtypePricing', [
            'roomtype_id' => $roomType->id,
            'NameAr' => 'خطة ب',
            'NameEn' => 'Plan B',
            'StartDate' => '2030-06-15',
            'EndDate' => '2030-07-15',
            'ActiveType' => 1,
            'DailyPrice' => 120,
            'MonthlyPrice' => 2800,
        ]);

        $overlap->assertOk();
        $overlap->assertJsonPath('success', false);
        $this->assertStringContainsString('overlaps', strtolower($overlap->json('message')));
    }

    public function test_allows_non_overlapping_pricing_plans_for_same_room_type(): void
    {
        $user = $this->pricingUser();
        $roomType = $this->roomTypeWithoutPricing();

        $first = $this->actingAs($user, 'api')->postJson('/api/users/addRoomtypePricing', [
            'roomtype_id' => $roomType->id,
            'NameAr' => 'خطة 1',
            'NameEn' => 'Plan One',
            'StartDate' => '2030-09-01',
            'EndDate' => '2030-09-30',
            'ActiveType' => 1,
            'DailyPrice' => 90,
            'MonthlyPrice' => 2200,
        ]);
        $first->assertJsonPath('success', true);

        $second = $this->actingAs($user, 'api')->postJson('/api/users/addRoomtypePricing', [
            'roomtype_id' => $roomType->id,
            'NameAr' => 'خطة 2',
            'NameEn' => 'Plan Two',
            'StartDate' => '2030-10-01',
            'EndDate' => '2030-10-31',
            'ActiveType' => 1,
            'DailyPrice' => 95,
            'MonthlyPrice' => 2300,
        ]);

        $second->assertJsonPath('success', true);
    }
}
