<?php

namespace Database\Seeders;

use App\Models\Room;
use App\Models\Suite;
use Illuminate\Console\Command;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Links demo rooms into suites so the room board shows suite blocks.
 *
 * Run alone: php artisan db:seed --class=DemoSuitesSeeder
 */
class DemoSuitesSeeder extends Seeder
{
    /** @var list<int> */
    private const FLOORS = [1, 8, 15];

    public function run(): void
    {
        $count = self::seed($this->command);
        $this->command?->info("Demo suites: {$count} suite(s) created or refreshed.");
    }

    public static function seed(?Command $command = null): int
    {
        $created = 0;

        $buildings = DB::table('buildings')->where('active', 1)->orderBy('id')->get();
        if ($buildings->isEmpty()) {
            $command?->warn('No active buildings — skip suite seeding.');

            return 0;
        }

        foreach ($buildings as $building) {
            $buildingLabel = trim((string) ($building->number ?? $building->name ?? $building->id));

            foreach (self::FLOORS as $floorNum) {
                $floor = DB::table('floors')
                    ->where('building_id', $building->id)
                    ->where('number', (string) $floorNum)
                    ->first();

                if (!$floor) {
                    continue;
                }

                $rooms = Room::query()
                    ->where('building_id', $building->id)
                    ->where('floor_id', $floor->id)
                    ->whereNull('suite_id')
                    ->where('active', 1)
                    ->orderBy('number')
                    ->get();

                if ($rooms->count() < 6) {
                    continue;
                }

                $definitions = [
                    ['name' => "Executive {$buildingLabel}-{$floorNum}", 'room_indexes' => [0, 1]],
                    ['name' => "Presidential {$buildingLabel}-{$floorNum}", 'room_indexes' => [4, 5]],
                ];

                foreach ($definitions as $definition) {
                    $roomIds = collect($definition['room_indexes'])
                        ->map(fn (int $index) => $rooms->get($index)?->id)
                        ->filter()
                        ->values()
                        ->all();

                    if (count($roomIds) < 2) {
                        continue;
                    }

                    $suite = Suite::updateOrCreate(
                        [
                            'building_id' => $building->id,
                            'number' => $definition['name'],
                        ],
                        [
                            'floor_id' => $floor->id,
                            'suiteStatus' => 0,
                            'active' => 1,
                        ]
                    );

                    Room::whereIn('id', $roomIds)->update(['suite_id' => $suite->id]);
                    $created++;
                }
            }
        }

        return $created;
    }
}
