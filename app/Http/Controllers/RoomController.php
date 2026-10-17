<?php

namespace App\Http\Controllers;

use App\Http\Requests\Room\StoreRoomRequest;
use App\Http\Requests\Room\UpdateRoomRequest;
use App\Http\Requests\Room\DestroyRoomRequest;
use App\Http\Requests\Room\StoreMultiRoomRequest;
use App\Http\Requests\Room\RoomIndexRequest;

use App\Jobs\SyncRoomAvailableToGrmsJob;
use App\Models\Room;
use App\Services\OccupancyBoardCache;
use App\Services\ReservationRoomStatusService;

class RoomController extends Controller
{
    public function index(RoomIndexRequest $request)
    {
        try {
            $perPage = \returnPerPage();
            $query = Room::with(['roomType', 'building', 'floor', 'suite'])->where('active', 1);

            if ($request->building_id) {
                $query->where('building_id', $request->building_id);
            }

            if ($request->floor_id) {
                $query->where('floor_id', $request->floor_id);
            }

            if ($request->suite_id) {
                $query->where('suite_id', $request->suite_id);
            }

            $rooms = $query->paginate($perPage);
            return \Pagination($rooms);
        } catch (\Exception $e) {
            return Failed($e->getMessage());
        }
    }

    public function addRoom(StoreRoomRequest $request)
    {
        if ($this->checkRoomInBuilding($request->building_id, $request->number)) {
            return Failed('The room already exists.');
        }

        $room = new Room();
        $room->building_id = $request->building_id;
        $room->floor_id = $request->floor_id;
        $room->suite_id = null;
        $room->number = $request->number;
        $room->capacity = $request->capacity ?? 0;
        $room->roomStatus = 1;
        $room->room_type_id = $request->room_type_id;
        $room->active = 1;

        try {
            $room->save();
            return SuccessData('Room added Successfully', $room);
        } catch (\Exception $e) {
            return Failed($e->getMessage());
        }
    }

    public function addMultiRoom(StoreMultiRoomRequest $request)
    {
        try {

$startNumber = (int)$request->numberRoom;
$endNumber = $startNumber + (int)$request->numberOfRoom - 1;

$roomsData = collect(range(0, $request->numberOfRoom - 1))->map(function ($i) use ($request) {
    return [
        'building_id'  => $request->buildingId,
        'floor_id'     => $request->floorId,
        'number'       => (int)$request->numberRoom + $i,
        'capacity'     => $request->capacity ?? 0,
        'room_type_id' => $request->typeRoom,
        'suite_id'     => null,
        'roomStatus'   => 1,
        'active'       => 1,
        'created_at'   => now(),
        'updated_at'   => now(),
    ];
})->toArray();

Room::insert($roomsData);

$addedRooms = Room::where('building_id', $request->buildingId)
    ->whereBetween('number', [$startNumber, $endNumber])
    ->get(['id', 'number']);
return Success('Rooms created successfully.', $addedRooms);
        } catch (\Exception $e) {
            return Failed($e->getMessage());
        }
    }

    public function updateRoom(UpdateRoomRequest $request)
    {
        $room = Room::find($request->id);

        if ($request->has('number') && $request->number != $room->number) {
            if ($this->checkRoomInBuilding($room->building_id, $request->number)) {
                return Failed('The room already exists.');
            } else {
                $room->number = $request->number;
            }
        }

        if ($request->has('room_type_id')) {
            $room->room_type_id = $request->room_type_id;
        }
        if ($request->has('capacity')) {
            $room->capacity = $request->capacity;
        }
        $markingReady = false;
        if ($request->has('roomStatus')) {
            $newStatus = (int) $request->roomStatus;
            $markingReady = $newStatus === ReservationRoomStatusService::ROOM_AVAILABLE
                && (int) $room->roomStatus !== ReservationRoomStatusService::ROOM_AVAILABLE;
            $room->roomStatus = $newStatus;
        }

        try {
            $room->save();
            OccupancyBoardCache::bump();

            if ($markingReady) {
                try {
                    SyncRoomAvailableToGrmsJob::dispatch($room->number);
                } catch (\Throwable $e) {
                    report($e);
                }
            }

            return Success('Room updated Successfully');
        } catch (\Exception $e) {
            return Failed($e->getMessage());
        }
    }

    public function deleteRoom(DestroyRoomRequest $request)
    {
        $room = Room::find($request->id);

        if ($room->isHasReservation()) {
            $room->active = 0;
            $room->save();
            return Success('Room deactivated due to existing reservations.');
        }
        else {
            try {
                $room->delete();
                return Success('Room deleted successfully.');
            } catch (\Exception $e) {
                return Failed($e->getMessage());
            }
        }
    }
    public function checkRoomInBuilding($building_id, $number)
    {
        $CheckRoom = Room::where('building_id', $building_id)->where('number', $number)->exists();
        if ($CheckRoom) {
            return true;
        }
        return false;
    }
}
