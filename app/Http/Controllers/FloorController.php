<?php

namespace App\Http\Controllers;

use App\Http\Requests\Floor\AddFloorRequest;
use App\Http\Requests\Floor\DeleteFloorRequest;
use App\Http\Requests\Floor\FloorIndexRequest;
use App\Http\Requests\Floor\UpdateFloorRequest;
use App\Models\Building;
use App\Models\Floor;
use App\Models\Room;
use App\Services\Cache\CacheInvalidator;
use App\Services\Cache\ReferenceDataCache;
use Exception;
use Illuminate\Support\Facades\DB;

class FloorController extends Controller
{
    public function index(FloorIndexRequest $request)
    {
        try {
            $floors = ReferenceDataCache::remember(
                'floors',
                'building:'.(int) $request->building_id,
                fn () => Floor::where('building_id', $request->building_id)
                    ->where('active', 1)
                    ->get()
            );

            return SuccessData('Floors fetched successfully', $floors);
        } catch (\Exception $e) {
            return Failed($e->getMessage());
        }
    }

    public function addFloor(AddFloorRequest $request)
    {
        $building = Building::find($request->building_id);
        $building->floors();

        $floor = new Floor;
        $floor->building_id = $building->id;
        $floor->number = $request->number;
        if ($request->has('lock_id')) {
            $floor->lock_id = $request->lock_id;
        }
        try {
            $floor->save();
            CacheInvalidator::propertyStructureChanged();

            return SuccessData('Floor added Successfully', $floor);
        } catch (Exception $e) {
            return Failed($e->getMessage());
        }
    }

    public function deleteFloor(DeleteFloorRequest $request)
    {
        $floor = Floor::with(['rooms', 'suites.rooms'])->where('id', $request->id_floor)->first();
        if (! $floor) {
            return Failed('Floor not found in the specified building.');
        }
        $hasReservation = false;
        foreach ($floor->rooms as $room) {
            if ($room->isHasReservation()) {
                $hasReservation = true;
                break;
            }
        }

        if (! $hasReservation) {
            foreach ($floor->suites as $suite) {
                foreach ($suite->rooms as $room) {
                    if ($room->isHasReservation()) {
                        $hasReservation = true;
                        break 2;
                    }
                }
            }
        }

        DB::beginTransaction();
        try {
            // have reservation
            if ($hasReservation) {
                $floor->active = 0;
                $floor->save();

                $floor->rooms()->update(['active' => 0]);

                $floor->suites()->update(['active' => 0]);
                $suiteIds = $floor->suites()->pluck('id');
                Room::whereIn('suite_id', $suiteIds)->update(['active' => 0]);

                DB::commit();
                CacheInvalidator::propertyStructureChanged();

                return Success('Floor deactivated due to existing reservations.');

            } else {
                // not have reservation
                $suiteIds = $floor->suites()->pluck('id');
                Room::whereIn('suite_id', $suiteIds)->delete();

                $floor->suites()->delete();
                $floor->rooms()->delete();
                $floor->delete();
                DB::commit();
                CacheInvalidator::propertyStructureChanged();

                return Success('Floor deleted successfully.');
            }
        } catch (Exception $e) {
            DB::rollBack();

            return Failed($e->getMessage());
        }
    }

    public function updateFloor(UpdateFloorRequest $request)
    {
        $floor = Floor::find($request->id);
        $floor->number = $request->number;
        try {
            $floor->update();
            CacheInvalidator::propertyStructureChanged();

            return Success('Floor updated Successfully');
        } catch (Exception $e) {
            return Failed($e->getMessage());
        }
    }
}
