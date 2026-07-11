<?php

namespace App\Http\Controllers;

use App\Http\Requests\Building\BuildingIdRequest;
use App\Http\Requests\Building\BuildingRequest;
use App\Http\Requests\Building\BuildingUpdateRequest;
use App\Http\Resources\Building\BuildingResource;
use App\Models\Building;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\Suite;
use App\Services\Cache\CacheInvalidator;
use App\Services\Cache\ReferenceDataCache;
use Illuminate\Support\Facades\DB;

class BuildingsController extends Controller
{
    public function index()
    {
        try {
            $perPage = \returnPerPage();
            $page = max(1, (int) request()->input('page', 1));
            $buildings = ReferenceDataCache::remember(
                'buildings',
                "page:{$page}:per:{$perPage}",
                fn () => Building::where('active', 1)->paginate($perPage)
            );

            return \Pagination($buildings);
        } catch (\Exception $e) {
            return Failed($e->getMessage());
        }
    }

    public function store(BuildingRequest $request)
    {
        DB::beginTransaction();
        try {
            $data = $request->only(['number', 'name', 'lock_id']);
            $building = Building::create($data);
            $building['floors'] = [];
            if ($request->numberOfFloor) {
                $floorsData = [];
                $count = $request->numberOfFloor;
                $startNumber = $request->numberFloor ?? 1;
                for ($i = 0; $i < $count; $i++) {
                    $floorsData[] = [
                        'building_id' => $building->id,
                        'number' => $startNumber + $i,
                    ];
                }
                $insertedFloors = $building->floors()->createMany($floorsData);
                $building['floors'] = $insertedFloors;

            }
            DB::commit();
            CacheInvalidator::propertyStructureChanged();

            return SuccessData('Building added Successfully', new BuildingResource($building));
        } catch (\Exception $e) {
            DB::rollBack();

            return Failed('An unexpected error occurred. Please try again later.');
        }
    }

    public function update(BuildingUpdateRequest $request)
    {
        $building = Building::find($request->id);
        $building->name = $request->name;
        $building->number = $request->number;
        try {
            $building->update();
            CacheInvalidator::propertyStructureChanged();

            return Success('Record Update Successfully');
        } catch (\Exception $e) {
            return Failed('An unexpected error occurred. Please try again later.');
        }
    }

    public function destroy(BuildingIdRequest $request)
    {

        $building = Building::findOrFail($request->id_building);

        $hasReservation = Reservation::whereIn('room_id', function ($query) use ($building) {
            $query->select('id')->from('rooms')
                ->where('building_id', $building->id)
                ->orWhereIn('floor_id', $building->floors->pluck('id'));
        })->exists();

        DB::beginTransaction();
        try {
            if ($hasReservation) {
                $building->update(['active' => 0]);
                $building->floors()->update(['active' => 0]);

                $floorIds = $building->floors()->pluck('id');
                Suite::whereIn('floor_id', $floorIds)->update(['active' => 0]);
                Room::whereIn('floor_id', $floorIds)->orWhere('building_id', $building->id)->update(['active' => 0]);

                $message = 'Building deactivated due to existing reservations.';
            } else {
                $floorIds = $building->floors()->pluck('id');

                Room::whereIn('floor_id', $floorIds)->orWhere('building_id', $building->id)->delete();
                Suite::whereIn('floor_id', $floorIds)->delete();
                $building->floors()->delete();
                $building->delete();

                $message = 'Building deleted successfully.';
            }

            DB::commit();
            CacheInvalidator::propertyStructureChanged();

            return Success($message);

        } catch (\Exception $e) {
            DB::rollBack();

            return Failed('An unexpected error occurred. Please try again later.');
        }
    }
}
