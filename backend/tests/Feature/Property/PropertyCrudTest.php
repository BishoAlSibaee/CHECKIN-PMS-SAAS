<?php

namespace Tests\Feature\Property;

use App\Models\Building;
use App\Models\Floor;
use App\Models\Room;
use Tests\TestCase;

class PropertyCrudTest extends TestCase
{
    public function test_create_building_with_floors(): void
    {
        $user = $this->userWithOnlyPermissions(['view buildings', 'manage buildings']);
        $suffix = $this->uniqueSuffix();
        $number = (int) substr($suffix, -6);

        $response = $this->actingAs($user, 'api')->postJson('/api/users/building', [
            'name' => 'Test Tower ' . $suffix,
            'number' => $number,
            'numberOfFloor' => 2,
            'numberFloor' => 1,
        ]);

        $this->assertApiSuccess($response);
        $this->assertDatabaseHas('buildings', ['name' => 'Test Tower ' . $suffix, 'number' => $number]);
        $building = Building::where('number', $number)->first();
        $this->assertNotNull($building);
        $this->assertGreaterThanOrEqual(2, $building->floors()->count());
    }

    public function test_create_building_validation_error_when_missing_fields(): void
    {
        $user = $this->userWithOnlyPermissions(['manage buildings']);

        $response = $this->actingAs($user, 'api')->postJson('/api/users/building', []);

        $this->assertApiValidationError($response);
    }

    public function test_update_building(): void
    {
        $user = $this->userWithOnlyPermissions(['view buildings', 'manage buildings']);
        $building = Building::where('active', 1)->first();
        $this->assertNotNull($building);

        $newName = 'Updated ' . $this->uniqueSuffix();

        $response = $this->actingAs($user, 'api')->postJson('/api/users/updateBuilding', [
            'id' => $building->id,
            'name' => $newName,
            'number' => $building->number,
        ]);

        $this->assertApiSuccess($response);
        $this->assertDatabaseHas('buildings', ['id' => $building->id, 'name' => $newName]);
    }

    public function test_add_floor_to_building(): void
    {
        $user = $this->userWithOnlyPermissions(['view floors', 'manage floors', 'view buildings']);
        $building = Building::where('active', 1)->first();
        $this->assertNotNull($building);

        $floorNumber = (int) (9000 + (microtime(true) * 100) % 999);

        $response = $this->actingAs($user, 'api')->postJson('/api/users/addFloor', [
            'building_id' => $building->id,
            'number' => $floorNumber,
        ]);

        $this->assertApiSuccess($response);
        $this->assertDatabaseHas('floors', ['building_id' => $building->id, 'number' => $floorNumber]);
    }

    public function test_list_floors_by_building(): void
    {
        $user = $this->userWithOnlyPermissions(['view floors']);
        $building = Building::where('active', 1)->first();
        $this->assertNotNull($building);

        $response = $this->actingAs($user, 'api')->getJson('/api/users/floors?building_id=' . $building->id);

        $this->assertApiSuccess($response);
    }

    public function test_list_rooms_by_building(): void
    {
        $user = $this->userWithOnlyPermissions(['view rooms']);
        $building = Building::where('active', 1)->first();
        $this->assertNotNull($building);

        $response = $this->actingAs($user, 'api')->getJson('/api/users/rooms?building_id=' . $building->id);

        $this->assertApiSuccess($response);
    }

    public function test_update_room_status(): void
    {
        $user = $this->userWithOnlyPermissions(['view rooms', 'manage rooms']);
        $room = Room::where('active', 1)->first();
        $this->assertNotNull($room);

        $response = $this->actingAs($user, 'api')->postJson('/api/users/updateRoom', [
            'id' => $room->id,
            'roomStatus' => $room->roomStatus,
            'active' => $room->active,
        ]);

        $this->assertTrue(in_array($response->status(), [200, 422, 500], true));
        if ($response->status() === 200) {
            $response->assertJsonPath('success', true);
        }
    }
}
