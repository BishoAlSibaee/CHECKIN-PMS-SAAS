<?php

namespace Tests\Feature\RoomType;

use App\Models\RoomType;
use App\Services\TestRoomTypeCleanupService;
use Tests\TestCase;

class CleanupTestRoomTypesTest extends TestCase
{
    public function test_cleanup_removes_orphan_test_room_types(): void
    {
        $suffix = uniqid();
        $orphan = RoomType::create([
            'name_ar' => 'نوع اختبار',
            'name_en' => 'Pricing Overlap Test ' . $suffix,
            'description' => 'Should be removed',
            'Min_daily_price' => 100,
            'Max_daily_price' => 200,
            'Min_monthly_price' => 2400,
            'Max_monthly_price' => 4800,
            'active_type' => 1,
        ]);

        $stats = app(TestRoomTypeCleanupService::class)->purgeOrphanTestRoomTypes();

        $this->assertGreaterThanOrEqual(1, $stats['deleted_types']);
        $this->assertDatabaseMissing('room_types', ['id' => $orphan->id]);
    }

    public function test_cleanup_skips_room_types_assigned_to_rooms(): void
    {
        $room = \App\Models\Room::query()->first();
        $this->assertNotNull($room);

        $linked = RoomType::create([
            'name_ar' => 'نوع مرتبط',
            'name_en' => 'Mixed API Test ' . uniqid(),
            'description' => 'Must stay while linked to a room',
            'Min_daily_price' => 100,
            'Max_daily_price' => 200,
            'Min_monthly_price' => 2400,
            'Max_monthly_price' => 4800,
            'active_type' => 1,
        ]);

        $originalTypeId = $room->room_type_id;
        $room->room_type_id = $linked->id;
        $room->save();

        try {
            $stats = app(TestRoomTypeCleanupService::class)->purgeOrphanTestRoomTypes();
            $this->assertGreaterThanOrEqual(1, $stats['skipped_in_use']);
            $this->assertDatabaseHas('room_types', ['id' => $linked->id]);
        } finally {
            $room->room_type_id = $originalTypeId;
            $room->save();
            app(TestRoomTypeCleanupService::class)->purgeRoomTypeTree((int) $linked->id);
        }
    }
}
