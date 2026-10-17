<?php

namespace Tests\Support;

use App\Models\Reservation;
use App\Models\ReservationRoom;
use App\Models\Room;
use App\Models\RoomType;
use App\Services\ReservationRoomStatusService;
use Illuminate\Support\Facades\DB;

trait InteractsWithReservationFixtures
{
    protected function roomHasReservationOverlap(int $roomId, string $startDate, string $endDate): bool
    {
        return ReservationRoom::where('room_id', $roomId)
            ->whereHas('reservation', function ($query) use ($startDate, $endDate) {
                $query->whereNotIn('reservation_status', [Reservation::STATUS_CANCELLED])
                    ->where('start_date', '<', $endDate)
                    ->where('expire_date', '>', $startDate);
            })
            ->exists();
    }

    protected function findOrCreateAvailableRoom(string $startDate, string $endDate): ?Room
    {
        $roomStatusService = app(ReservationRoomStatusService::class);

        foreach (Room::query()
            ->where('active', 1)
            ->whereHas('roomType')
            ->orderBy('id')
            ->get() as $candidate) {
            // A room with a guest still checked in blocks ANY future booking
            // (not just overlapping dates) until checkout is registered —
            // same date-blind rule ReservationController::makeReservation()
            // enforces — so a fixture room must be clear of that too, not
            // just clear of date-range overlap.
            if ($roomStatusService->findActiveInHouseReservationId($candidate->id) !== null) {
                continue;
            }

            if (! $this->roomHasReservationOverlap($candidate->id, $startDate, $endDate)) {
                Room::where('id', $candidate->id)->update([
                    'roomStatus' => ReservationRoomStatusService::ROOM_AVAILABLE,
                ]);

                return $candidate->fresh();
            }
        }

        return $this->createIsolatedTestRoom();
    }

    protected function createIsolatedTestRoom(): ?Room
    {
        $roomType = RoomType::query()->first();
        if (! $roomType) {
            return null;
        }

        $buildingId = DB::table('buildings')->value('id');
        $floorId = DB::table('floors')->where('building_id', $buildingId)->value('id');
        if (! $buildingId || ! $floorId) {
            return null;
        }

        $number = 'T'.substr((string) microtime(true), -6).random_int(10, 99);

        return Room::unguarded(function () use ($number, $buildingId, $floorId, $roomType) {
            return Room::create([
                'number' => $number,
                'building_id' => $buildingId,
                'floor_id' => $floorId,
                'suite_id' => null,
                'room_type_id' => $roomType->id,
                'capacity' => 2,
                'active' => 1,
                'roomStatus' => ReservationRoomStatusService::ROOM_AVAILABLE,
            ]);
        });
    }
}
