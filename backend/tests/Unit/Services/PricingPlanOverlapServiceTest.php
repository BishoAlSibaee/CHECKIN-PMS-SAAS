<?php

namespace Tests\Unit\Services;

use App\Services\PricingPlanOverlapService;
use Tests\TestCase;

class PricingPlanOverlapServiceTest extends TestCase
{
    public function test_has_conflict_returns_boolean(): void
    {
        $service = app(PricingPlanOverlapService::class);
        $roomType = \App\Models\RoomType::first();

        if (!$roomType) {
            $this->markTestSkipped('No room types in database.');
        }

        $result = $service->hasConflict($roomType->id, '2099-01-01', '2099-01-31');

        $this->assertIsBool($result);
    }

    public function test_conflict_message_includes_plan_name(): void
    {
        $service = app(PricingPlanOverlapService::class);
        $link = \App\Models\RoomtypePricingplan::with('pricingplan')->first();

        if (!$link) {
            $this->markTestSkipped('No pricing plan links in database.');
        }

        $message = $service->conflictMessage($link);

        $this->assertStringContainsString('pricing plan', strtolower($message));
    }
}
