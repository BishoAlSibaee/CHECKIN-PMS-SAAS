<?php

namespace Tests\Feature\Finance;

use App\Models\Building;
use App\Models\Floor;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\Suite;
use Tests\TestCase;

class RevenueByEntityTest extends TestCase
{
    private function dateRange(): array
    {
        return ['start_date' => '2026-08-01', 'end_date' => '2026-08-31'];
    }

    public function test_revenue_total(): void
    {
        $user = $this->userWithOnlyPermissions(['view revenue']);

        $this->assertApiSuccess(
            $this->actingAs($user, 'api')->getJson('/api/users/revenue/total?' . http_build_query($this->dateRange()))
        );
    }

    public function test_revenue_by_room(): void
    {
        $user = $this->userWithOnlyPermissions(['view revenue']);
        $room = Room::where('active', 1)->first();
        $this->assertNotNull($room);

        $this->assertApiSuccess(
            $this->actingAs($user, 'api')->getJson(
                '/api/users/revenue/room/' . $room->id . '?' . http_build_query($this->dateRange())
            )
        );
    }

    public function test_revenue_by_suite(): void
    {
        $user = $this->userWithOnlyPermissions(['view revenue']);
        $suite = Suite::first();
        if (!$suite) {
            $this->markTestSkipped('No suites in database.');
        }

        $this->assertApiSuccess(
            $this->actingAs($user, 'api')->getJson(
                '/api/users/revenue/suite/' . $suite->id . '?' . http_build_query($this->dateRange())
            )
        );
    }

    public function test_revenue_by_floor(): void
    {
        $user = $this->userWithOnlyPermissions(['view revenue']);
        $floor = Floor::first();
        $this->assertNotNull($floor);

        $this->assertApiSuccess(
            $this->actingAs($user, 'api')->getJson(
                '/api/users/revenue/floor/' . $floor->id . '?' . http_build_query($this->dateRange())
            )
        );
    }

    public function test_revenue_by_building(): void
    {
        $user = $this->userWithOnlyPermissions(['view revenue']);
        $building = Building::where('active', 1)->first();
        $this->assertNotNull($building);

        $this->assertApiSuccess(
            $this->actingAs($user, 'api')->getJson(
                '/api/users/revenue/building/' . $building->id . '?' . http_build_query($this->dateRange())
            )
        );
    }

    public function test_revenue_by_room_type(): void
    {
        $user = $this->userWithOnlyPermissions(['view revenue']);
        $roomType = RoomType::first();
        if (!$roomType) {
            $this->markTestSkipped('No room types in database.');
        }

        $this->assertApiSuccess(
            $this->actingAs($user, 'api')->getJson(
                '/api/users/revenue/roomtype/' . $roomType->id . '?' . http_build_query($this->dateRange())
            )
        );
    }

    public function test_revenue_endpoints_forbidden_without_permission(): void
    {
        $user = $this->userWithOnlyPermissions(['view earnings']);

        $this->assertApiForbidden(
            $this->actingAs($user, 'api')->getJson('/api/users/revenue/total?' . http_build_query($this->dateRange()))
        );
    }
}
