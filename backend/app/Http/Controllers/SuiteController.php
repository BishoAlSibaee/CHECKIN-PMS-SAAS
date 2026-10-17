<?php

namespace App\Http\Controllers;

use App\Http\Requests\Suite\AddSuiteRequest;
use App\Http\Requests\Suite\DeleteSuiteRequest;
use App\Http\Requests\Suite\UpdateSuiteRequest;
use App\Http\Requests\Suite\SuiteIndexRequest;
use App\Models\Suite;
use App\Models\Room;
use Illuminate\Support\Facades\DB;
use Exception;

class SuiteController extends Controller
{
    public function index(SuiteIndexRequest $request)
    {
        try {
            $perPage = \returnPerPage();
            $query = Suite::with(['rooms.roomType'])->where('active', 1);

            if ($request->building_id) {
                $query->where('building_id', $request->building_id);
            }
            if ($request->floor_id) {
                $query->where('floor_id', $request->floor_id);
            }

            $suites = $query->paginate($perPage);
            return \Pagination($suites);
        } catch (\Exception $e) {
            return Failed($e->getMessage());
        }
    }

    public function addSuite(AddSuiteRequest $request)
    {
        DB::beginTransaction();
        try {
            $roomIds = collect($request->rooms)->pluck('id');
            $rooms = Room::whereIn('id', $roomIds)
                ->where('building_id', $request->building_id)
                ->where('floor_id', $request->floor_id)
                ->where('active', 1)
                ->get();

            if ($rooms->count() !== $roomIds->count()) {
                DB::rollBack();
                return Failed('One or more selected rooms are invalid for this floor.', 422);
            }

            if ($rooms->contains(fn ($room) => $room->suite_id !== null)) {
                DB::rollBack();
                return Failed('One or more selected rooms already belong to a suite.', 422);
            }

            $suite = Suite::create([
                'building_id' => $request->building_id,
                'floor_id' => $request->floor_id,
                'number' => $request->number,
                'suiteStatus' => 0,
                'active' => 1,
                'lock_id' => $request->lock_id ?? null,
            ]);

            Room::whereIn('id', $roomIds)->update(['suite_id' => $suite->id]);

            DB::commit();
            return SuccessData('Suite added Successfully', $suite->load(['rooms.roomType']));
        } catch (Exception $e) {
            DB::rollBack();
            return Failed($e->getMessage());
        }
    }

    public function updateSuite(UpdateSuiteRequest $request)
    {
        $suite = Suite::with('rooms')->find($request->id);

        if (!$suite) {
            return Failed('Suite not found.', 404);
        }

        DB::beginTransaction();
        try {
            if ($request->has('number') && $request->number !== null) {
                $suite->number = $request->number;
            }

            if ($request->has('active')) {
                $suite->active = (int) $request->boolean('active');
            }

            $suite->save();

            if ($request->filled('room_ids_to_add')) {
                $ids = collect($request->room_ids_to_add);
                $rooms = Room::whereIn('id', $ids)
                    ->where('building_id', $suite->building_id)
                    ->where('floor_id', $suite->floor_id)
                    ->where('active', 1)
                    ->get();

                if ($rooms->count() !== $ids->count()) {
                    DB::rollBack();
                    return Failed('One or more rooms are invalid for this suite floor.', 422);
                }

                if ($rooms->contains(fn ($room) => $room->suite_id !== null && (int) $room->suite_id !== (int) $suite->id)) {
                    DB::rollBack();
                    return Failed('One or more rooms already belong to another suite.', 422);
                }

                Room::whereIn('id', $ids)->update(['suite_id' => $suite->id]);
            }

            if ($request->filled('room_ids_to_remove')) {
                Room::whereIn('id', $request->room_ids_to_remove)
                    ->where('suite_id', $suite->id)
                    ->update(['suite_id' => null]);
            }

            DB::commit();

            $message = $suite->active === 0
                ? 'Suite deactivated successfully.'
                : 'Suite updated Successfully';

            return SuccessData($message, $suite->fresh(['rooms.roomType']));
        } catch (Exception $e) {
            DB::rollBack();
            return Failed($e->getMessage());
        }
    }

    public function deleteSuite(DeleteSuiteRequest $request)
    {
        $suite = Suite::with('rooms')->find($request->id_suite);

        if (!$suite) {
            return Failed('Suite not found in the specified building.');
        }
        $hasReservation = $suite->rooms->contains->isHasReservation();

        DB::beginTransaction();
        try {
            if ($hasReservation) {
                $suite->update(['active' => 0]);

                if ($request->delete_rooms) {
                    $suite->rooms()->update(['active' => 0]);
                }

                DB::commit();
                return Success('Suite deactivated due to existing reservations.');
            }

            if ($request->delete_rooms) {
                $suite->rooms()->delete();
            } else {
                $suite->rooms()->update(['suite_id' => null]);
            }

            $suite->delete();

            DB::commit();
            return Success('Suite deleted successfully.');

        } catch (Exception $e) {
            DB::rollBack();
            return Failed($e->getMessage());
        }
    }
}
