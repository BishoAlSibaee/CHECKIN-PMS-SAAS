<?php

namespace App\Console\Commands;

use App\Models\Building;
use App\Models\Floor;
use App\Models\Room;
use App\Models\RoomType;
use App\Services\OccupancyBoardCache;
use Illuminate\Console\Command;

class EnsureStandaloneRoomsCommand extends Command
{
    protected $signature = 'demo:ensure-standalone-rooms
                            {--per-floor=4 : Standalone rooms to ensure on each active floor}
                            {--building= : Limit to one building id}';

    protected $description = 'Ensure standalone rooms (no suite) on every active floor of every active building';

    public function handle(): int
    {
        $perFloor = max(1, (int) $this->option('per-floor'));
        $buildingFilter = $this->option('building');

        $roomType = RoomType::query()->where('active_type', 1)->orderBy('id')->first();
        if (!$roomType) {
            $this->error('No active room type found.');

            return self::FAILURE;
        }

        $buildingsQuery = Building::query()->where('active', 1)->orderBy('id');
        if ($buildingFilter !== null && $buildingFilter !== '') {
            $buildingsQuery->where('id', (int) $buildingFilter);
        }

        $buildings = $buildingsQuery->get();
        if ($buildings->isEmpty()) {
            $this->error('No active buildings found.');

            return self::FAILURE;
        }

        $created = 0;
        $skipped = 0;

        foreach ($buildings as $building) {
            $floors = Floor::query()
                ->where('building_id', $building->id)
                ->where('active', 1)
                ->orderBy('id')
                ->get();

            if ($floors->isEmpty()) {
                $this->warn("Building #{$building->id} ({$building->name}): no active floors — skipped.");

                continue;
            }

            $buildingCreated = 0;

            foreach ($floors as $floor) {
                $floorKey = preg_replace('/\D+/', '', (string) $floor->number) ?: (string) $floor->id;

                for ($seq = 1; $seq <= $perFloor; $seq++) {
                    $number = $this->roomNumber($building->id, $floorKey, $seq);

                    if (Room::query()->where('building_id', $building->id)->where('number', $number)->exists()) {
                        $skipped++;
                        continue;
                    }

                    $room = new Room();
                    $room->building_id = $building->id;
                    $room->floor_id = $floor->id;
                    $room->suite_id = null;
                    $room->number = $number;
                    $room->capacity = 2;
                    $room->room_type_id = $roomType->id;
                    $room->roomStatus = 1;
                    $room->active = 1;
                    $room->save();

                    $created++;
                    $buildingCreated++;
                }
            }

            $standaloneTotal = Room::query()
                ->where('building_id', $building->id)
                ->where('active', 1)
                ->whereNull('suite_id')
                ->count();

            $this->info(sprintf(
                'Building #%d (%s): %d floor(s), +%d room(s), standalone total: %d',
                $building->id,
                $building->name,
                $floors->count(),
                $buildingCreated,
                $standaloneTotal
            ));
        }

        OccupancyBoardCache::bump();

        $this->newLine();
        $this->info("Done: {$created} created, {$skipped} skipped (already existed).");

        return self::SUCCESS;
    }

    /** Unique per building: B{buildingId}-F{floorKey}-{seq} */
    private function roomNumber(int $buildingId, string $floorKey, int $seq): string
    {
        return sprintf('B%d-F%s-%02d', $buildingId, $floorKey, $seq);
    }
}
