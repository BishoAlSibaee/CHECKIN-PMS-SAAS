<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\Facilitie;
use App\Models\FacilitiesType;
use App\Http\Requests\Facilities\AddFacilitiesRequest;
use App\Http\Requests\Facilities\UpdateFacilitiesRequest;
use App\Http\Requests\Facilities\DeleteFacilitiesRequest;
use App\Http\Requests\Facilities\AddFacilitiesTypeRequest;
use App\Http\Requests\Facilities\UpdateFacilitiesTypeRequest;
use App\Http\Requests\Facilities\DeleteFacilitiesTypeRequest;
use App\Http\Requests\Facilities\GetFacilitiesByBuildingRequest;
use App\Http\Requests\Facilities\GetFacilitiesByFloorRequest;

class FacilitiesController extends Controller
{
    public function index(Request $request)
    {
        try {
            $perPage = \returnPerPage();
            $facilities = Facilitie::with(['building', 'floor', 'facilitiesType'])->paginate($perPage);
            return \Pagination($facilities);
        } catch (\Exception $e) {
            return \Failed($e->getMessage());
        }
    }

    public function store(AddFacilitiesRequest $request)
    {
        DB::beginTransaction();
        try {
            $facilitie = Facilitie::create([
                'name_ar' => $request->name_ar,
                'name_en' => $request->name_en,
                'building_id' => $request->building_id,
                'floor_id' => $request->floor_id ?? 0,
                'facilities_types_id' => $request->facilities_types_id,
                'lock_data' => $request->lock_data ?? null,
            ]);
            $facilitie->load(['building', 'floor', 'facilitiesType']);
            DB::commit();
            return \SuccessData('Facility added Successfully', $facilitie);
        } catch (\Exception $e) {
            DB::rollBack();
            return \Failed('An unexpected error occurred. Please try again later.');
        }
    }

    public function update(UpdateFacilitiesRequest $request)
    {
        $facilitie = Facilitie::find($request->id);

        try {
            $facilitie->update([
                'name_ar' => $request->name_ar ?? $facilitie->name_ar,
                'name_en' => $request->name_en ?? $facilitie->name_en,
                'building_id' => $request->building_id ?? $facilitie->building_id,
                'floor_id' => $request->floor_id ?? $facilitie->floor_id,
                'facilities_types_id' => $request->facilities_types_id ?? $facilitie->facilities_types_id,
                'lock_data' => $request->lock_data ?? $facilitie->lock_data,
            ]);
            $facilitie->load(['building', 'floor', 'facilitiesType']);
            return \Success('Record Update Successfully');
        } catch (\Exception $e) {
            return \Failed('An unexpected error occurred. Please try again later.');
        }
    }

    public function destroy(DeleteFacilitiesRequest $request)
    {
        DB::beginTransaction();
        try {
            $facilitie = Facilitie::find($request->id);
            $facilitie->delete();
            DB::commit();
            return \Success('Facility deleted successfully');
        } catch (\Exception $e) {
            DB::rollBack();
            return \Failed('An unexpected error occurred. Please try again later.');
        }
    }

    public function getByBuilding(GetFacilitiesByBuildingRequest $request)
    {
        try {
            $facilities = Facilitie::with([ 'floor', 'facilitiesType'])
                ->where('building_id', $request->building_id)
                ->get();
            return \SuccessData('Facilities fetched successfully', $facilities);
        } catch (\Exception $e) {
            return \Failed($e->getMessage());
        }
    }

    public function getByFloor(GetFacilitiesByFloorRequest $request)
    {
        try {
            $facilities = Facilitie::with(['building',  'facilitiesType'])
                ->where('floor_id', $request->floor_id)
                ->get();
            return \SuccessData('Facilities fetched successfully', $facilities);
        } catch (\Exception $e) {
            return \Failed($e->getMessage());
        }
    }

    public function typeIndex()
    {
        try {
            $perPage = \returnPerPage();
            $facilitiesType = FacilitiesType::paginate($perPage);
            return \Pagination($facilitiesType);
        } catch (\Exception $e) {
            return \Failed($e->getMessage());
        }
    }

    public function typeStore(AddFacilitiesTypeRequest $request)
    {
        DB::beginTransaction();
        try {
            $facilitiesType = FacilitiesType::create([
                'name_ar' => $request->name_ar,
                'name_en' => $request->name_en,
                'description' => $request->description ?? null,
            ]);
            DB::commit();
            return \SuccessData('Facility Type added Successfully', $facilitiesType);
        } catch (\Exception $e) {
            DB::rollBack();
            return \Failed('An unexpected error occurred. Please try again later.');
        }
    }

    public function typeUpdate(UpdateFacilitiesTypeRequest $request)
    {
        $facilitiesType = FacilitiesType::find($request->id);

        try {
            $facilitiesType->update([
                'name_ar' => $request->name_ar ?? $facilitiesType->name_ar,
                'name_en' => $request->name_en ?? $facilitiesType->name_en,
                'description' => $request->description ?? $facilitiesType->description,
            ]);
            return \Success('Record Update Successfully');
        } catch (\Exception $e) {
            return \Failed('An unexpected error occurred. Please try again later.');
        }
    }

    public function typeDestroy(DeleteFacilitiesTypeRequest $request)
    {
        DB::beginTransaction();
        try {
            // Check if facility type is being used
            $facilitiesCount = Facilitie::where('facilities_types_id', $request->id)->count();
            if ($facilitiesCount > 0) {
                return \Failed('Cannot delete facility type as it is linked to existing facilities.');
            }

            $facilitiesType = FacilitiesType::find($request->id);
            $facilitiesType->delete();
            DB::commit();
            return \Success('Facility Type deleted successfully');
        } catch (\Exception $e) {
            DB::rollBack();
            return \Failed('An unexpected error occurred. Please try again later.');
        }
    }

    public function typeShow(Request $request)
    {
        $request->validate([
            'id' => 'required|numeric|exists:facilities_type,id',
        ]);

        try {
            $facilitiesType = FacilitiesType::find($request->id);
            return \SuccessData('Facility Type fetched successfully', $facilitiesType);
        } catch (\Exception $e) {
            return \Failed($e->getMessage());
        }
    }
}
