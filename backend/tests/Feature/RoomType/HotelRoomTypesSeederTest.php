<?php

namespace Tests\Feature\RoomType;

use App\Models\RoomType;
use Database\Seeders\HotelRoomTypesSeeder;
use Tests\TestCase;

class HotelRoomTypesSeederTest extends TestCase
{
    use \Illuminate\Foundation\Testing\DatabaseTransactions;

    protected function connectionsToTransact(): array
    {
        return ['mysql', 'mysql2'];
    }

    public function test_seeder_creates_five_realistic_room_types(): void
    {
        $this->artisan('db:seed', [
            '--class' => HotelRoomTypesSeeder::class,
            '--force' => true,
            '--no-interaction' => true,
        ])->assertSuccessful();

        // 6, not 5 — the baseline property fixture migration
        // (2026_10_20_000000) seeds one "Standard Room"/"غرفة قياسية" type
        // unconditionally (same pattern as create_hotels_table's default
        // Hotel row); this seeder adds its own 5 on top without touching it.
        $this->assertSame(6, RoomType::count());
        $this->assertDatabaseHas('room_types', ['name_en' => 'Standard', 'name_ar' => 'ستاندرد']);
        $this->assertDatabaseHas('room_types', ['name_en' => 'Superior', 'name_ar' => 'ممتاز']);
        $this->assertDatabaseHas('room_types', ['name_en' => 'Deluxe', 'name_ar' => 'ديلوكس']);
        $this->assertDatabaseHas('room_types', ['name_en' => 'Family', 'name_ar' => 'عائلي']);
        $this->assertDatabaseHas('room_types', ['name_en' => 'Suite', 'name_ar' => 'جناح']);
        $this->assertDatabaseMissing('room_types', ['name_en' => 'Deluxe Test']);
    }
}
