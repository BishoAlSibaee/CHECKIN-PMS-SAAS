<?php

namespace Tests\Support;

use App\Models\RoomType;
use App\Services\TestRoomTypeCleanupService;

trait InteractsWithTestRoomTypes
{
    /** @var list<int> */
    private array $testRoomTypeIds = [];

    protected function createTestRoomType(array $overrides = []): RoomType
    {
        $suffix = uniqid();

        $roomType = RoomType::create(array_merge([
            'name_ar'           => 'نوع اختبار',
            'name_en'           => 'Pricing Overlap Test ' . $suffix,
            'description'       => 'Automated test room type',
            'Min_daily_price'   => 100,
            'Max_daily_price'   => 200,
            'Min_monthly_price' => 2400,
            'Max_monthly_price' => 4800,
            'active_type'       => 1,
        ], $overrides));

        $this->testRoomTypeIds[] = (int) $roomType->id;

        return $roomType;
    }

    protected function trackTestRoomType(int $roomTypeId): void
    {
        if (!in_array($roomTypeId, $this->testRoomTypeIds, true)) {
            $this->testRoomTypeIds[] = $roomTypeId;
        }
    }

    protected function cleanupTestRoomTypes(): void
    {
        if ($this->testRoomTypeIds === []) {
            return;
        }

        $cleanup = app(TestRoomTypeCleanupService::class);

        foreach ($this->testRoomTypeIds as $roomTypeId) {
            $cleanup->purgeRoomTypeTree($roomTypeId);
        }

        $this->testRoomTypeIds = [];
    }

    protected function tearDownTestRoomTypes(): void
    {
        $this->cleanupTestRoomTypes();
    }
}
