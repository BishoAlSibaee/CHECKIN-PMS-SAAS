<?php

namespace Tests\Feature\RoomType;

use App\Models\RoomType;
use Database\Seeders\HotelRoomTypesSeeder;
use Tests\TestCase;

class HotelRoomTypesSeederTest extends TestCase
{
    public function test_seeder_creates_five_realistic_room_types(): void
    {
        $this->artisan('db:seed', [
            '--class' => HotelRoomTypesSeeder::class,
            '--force' => true,
            '--no-interaction' => true,
        ])->assertSuccessful();

        $this->assertSame(5, RoomType::count());
        $this->assertDatabaseHas('room_types', ['name_en' => 'Standard', 'name_ar' => 'ستاندرد']);
        $this->assertDatabaseHas('room_types', ['name_en' => 'Superior', 'name_ar' => 'ممتاز']);
        $this->assertDatabaseHas('room_types', ['name_en' => 'Deluxe', 'name_ar' => 'ديلوكس']);
        $this->assertDatabaseHas('room_types', ['name_en' => 'Family', 'name_ar' => 'عائلي']);
        $this->assertDatabaseHas('room_types', ['name_en' => 'Suite', 'name_ar' => 'جناح']);
        $this->assertDatabaseMissing('room_types', ['name_en' => 'Deluxe Test']);
    }
}
