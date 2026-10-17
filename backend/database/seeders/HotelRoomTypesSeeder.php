<?php

namespace Database\Seeders;

use App\Models\Room;
use App\Models\RoomType;
use App\Services\TestRoomTypeCleanupService;
use Illuminate\Database\Seeder;

/**
 * Ensures five realistic hotel room categories (idempotent).
 *
 * Run: php artisan db:seed --class=HotelRoomTypesSeeder
 */
class HotelRoomTypesSeeder extends Seeder
{
    /** @return list<array<string, mixed>> */
    public static function catalog(): array
    {
        return [
            [
                'name_en' => 'Standard',
                'name_ar' => 'ستاندرد',
                'description' => 'Comfortable room with essential amenities for business or short stays.',
                'Min_daily_price' => 180,
                'Max_daily_price' => 280,
                'Min_monthly_price' => 4200,
                'Max_monthly_price' => 6500,
            ],
            [
                'name_en' => 'Superior',
                'name_ar' => 'ممتاز',
                'description' => 'Spacious room with upgraded bedding and city or garden view.',
                'Min_daily_price' => 240,
                'Max_daily_price' => 360,
                'Min_monthly_price' => 5600,
                'Max_monthly_price' => 8200,
            ],
            [
                'name_en' => 'Deluxe',
                'name_ar' => 'ديلوكس',
                'description' => 'Premium room with lounge area, minibar, and enhanced in-room services.',
                'Min_daily_price' => 320,
                'Max_daily_price' => 480,
                'Min_monthly_price' => 7500,
                'Max_monthly_price' => 11500,
            ],
            [
                'name_en' => 'Family',
                'name_ar' => 'عائلي',
                'description' => 'Large room for families with extra beds and connecting options.',
                'Min_daily_price' => 380,
                'Max_daily_price' => 520,
                'Min_monthly_price' => 8800,
                'Max_monthly_price' => 12500,
            ],
            [
                'name_en' => 'Suite',
                'name_ar' => 'جناح',
                'description' => 'Executive suite with separate living area and VIP amenities.',
                'Min_daily_price' => 550,
                'Max_daily_price' => 850,
                'Min_monthly_price' => 13000,
                'Max_monthly_price' => 20000,
            ],
        ];
    }

    public function run(): void
    {
        $typeIds = [];

        foreach (self::catalog() as $type) {
            $typeIds[] = RoomType::updateOrCreate(
                ['name_en' => $type['name_en']],
                [
                    'name_ar'           => $type['name_ar'],
                    'description'       => $type['description'],
                    'Min_daily_price'   => $type['Min_daily_price'],
                    'Max_daily_price'   => $type['Max_daily_price'],
                    'Min_monthly_price' => $type['Min_monthly_price'],
                    'Max_monthly_price' => $type['Max_monthly_price'],
                    'active_type'       => 1,
                ]
            )->id;
        }

        $this->removeLegacyTestType();
        $this->distributeRoomTypes($typeIds);

        $this->command?->info('Hotel room types ready: ' . count($typeIds) . ' categories.');
    }

    private function removeLegacyTestType(): void
    {
        $legacy = RoomType::query()->where('name_en', 'Deluxe Test')->first();
        if (!$legacy) {
            return;
        }

        if ($legacy->rooms()->exists()) {
            $fallback = RoomType::query()->where('name_en', 'Deluxe')->value('id')
                ?? RoomType::query()->where('name_en', 'Standard')->value('id');

            if ($fallback) {
                Room::query()->where('room_type_id', $legacy->id)->update(['room_type_id' => $fallback]);
            }
        }

        if (!$legacy->rooms()->exists()) {
            app(TestRoomTypeCleanupService::class)->purgeRoomTypeTree((int) $legacy->id);
            $this->command?->line('  Removed legacy test room type: Deluxe Test');
        }
    }

    /** @param list<int> $typeIds */
    private function distributeRoomTypes(array $typeIds): void
    {
        if ($typeIds === []) {
            return;
        }

        $rooms = Room::query()->orderBy('id')->get(['id', 'room_type_id']);
        if ($rooms->isEmpty()) {
            return;
        }

        $assigned = 0;
        foreach ($rooms as $index => $room) {
            $targetTypeId = $typeIds[$index % count($typeIds)];
            if ((int) $room->room_type_id === (int) $targetTypeId) {
                continue;
            }

            Room::query()->whereKey($room->id)->update(['room_type_id' => $targetTypeId]);
            $assigned++;
        }

        if ($assigned > 0) {
            $this->command?->line("  Assigned realistic room types to {$assigned} room(s).");
        }
    }
}
