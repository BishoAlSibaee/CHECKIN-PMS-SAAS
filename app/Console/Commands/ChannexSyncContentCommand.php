<?php

namespace App\Console\Commands;

use App\Models\Hotel;
use App\Models\RoomType;
use App\Services\Channex\ChannexContentSyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class ChannexSyncContentCommand extends Command
{
    protected $signature = 'channex:sync-content {--hotel= : Hotel id to sync. Defaults to every active hotel.}';

    protected $description = 'Create/update a hotel\'s Channex property and room types, and sync its active OTA rate plans';

    public function handle(ChannexContentSyncService $service): int
    {
        $hotelIds = $this->option('hotel')
            ? [(int) $this->option('hotel')]
            : Hotel::where('is_active', true)->pluck('id')->all();

        $failures = 0;
        foreach ($hotelIds as $hotelId) {
            try {
                $property = $service->syncHotel((int) $hotelId);
                $this->info("Hotel #{$hotelId} -> Channex property {$property->channex_property_id}");

                $roomTypeIds = DB::table('rooms')
                    ->join('buildings', 'buildings.id', '=', 'rooms.building_id')
                    ->where('buildings.hotel_id', $hotelId)
                    ->where('rooms.active', 1)
                    ->distinct()
                    ->pluck('rooms.room_type_id');

                foreach ($roomTypeIds as $roomTypeId) {
                    $mapping = $service->syncRoomType((int) $hotelId, (int) $roomTypeId);
                    $roomType = RoomType::find($roomTypeId);
                    $this->line("  room type '{$roomType?->name_en}' -> {$mapping->channex_room_type_id}");
                }

                $ratePlans = \App\Models\OtaRatePlan::where('hotel_id', $hotelId)->where('is_active', true)->get();
                foreach ($ratePlans as $ratePlan) {
                    $mapping = $service->syncRatePlan($ratePlan);
                    $this->line("  rate plan '{$ratePlan->name_en}' -> {$mapping->channex_rate_plan_id}");
                }
            } catch (Throwable $e) {
                $failures++;
                $this->error("Hotel #{$hotelId} sync failed: {$e->getMessage()}");
            }
        }

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }
}
