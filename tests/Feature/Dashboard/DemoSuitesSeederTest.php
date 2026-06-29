<?php

namespace Tests\Feature\Dashboard;

use App\Models\Room;
use App\Models\Suite;
use Database\Seeders\DemoSuitesSeeder;
use Tests\TestCase;

class DemoSuitesSeederTest extends TestCase
{
    public function test_demo_suites_seeder_links_rooms_for_occupancy_board(): void
    {
        if (Room::count() < 6) {
            $this->markTestSkipped('Need demo rooms in database.');
        }

        DemoSuitesSeeder::seed();

        $this->assertGreaterThan(0, Suite::count());
        $this->assertGreaterThan(0, Room::whereNotNull('suite_id')->count());

        $user = $this->userWithApiPermissions();
        $response = $this->actingAs($user, 'api')->getJson('/api/users/rooms/occupancy-board?date=2026-08-01');
        $response->assertOk();

        $rooms = $response->json('data.rooms') ?? [];
        $withSuite = array_filter($rooms, fn (array $row) => !empty($row['suite_id']));
        $this->assertNotEmpty($withSuite, 'Occupancy board should include rooms grouped under suites.');
    }
}
