<?php

namespace App\Services;

use App\Models\Reservation;
use App\Models\ReservationRoom;
use App\Models\Room;

class ReservationRoomStatusService
{
    /** roomStatus: 1 = available, 2 = occupied, 3 = needs preparation/cleaning, 4 = out of service */
    public const ROOM_AVAILABLE = 1;
    public const ROOM_OCCUPIED = 2;
    public const ROOM_PREPARATION = 3;
    public const ROOM_OUT_OF_SERVICE = 4;

    public function syncForReservation(Reservation $reservation): void
    {
        $reservation->loadMissing('reservationRooms');

        if (Reservation::isCancelled((int) $reservation->reservation_status)) {
            $this->releaseRoomsAfterCancellation($reservation, false);
            OccupancyBoardCache::bump();

            return;
        }

        foreach ($reservation->reservationRooms as $resRoom) {
            if (!$resRoom->room_id) {
                continue;
            }

            if ((int) $reservation->logedin === Reservation::LOGEDIN_IN_HOUSE
                && (int) $reservation->reservation_status === Reservation::STATUS_CONFIRMED) {
                Room::where('id', $resRoom->room_id)->update(['roomStatus' => self::ROOM_OCCUPIED]);
            }
        }

        OccupancyBoardCache::bump();
    }

    /**
     * Free room inventory after cancellation / refund.
     *
     * @param  bool  $needsPreparation  When true (e.g. guest was in-house), mark for cleaning instead of available.
     */
    public function releaseRoomsAfterCancellation(Reservation $reservation, bool $needsPreparation = false): void
    {
        $reservation->loadMissing('reservationRooms');

        foreach ($reservation->reservationRooms as $resRoom) {
            if (!$resRoom->room_id) {
                continue;
            }

            if ($needsPreparation) {
                $this->markNeedsPreparation($resRoom->room_id);
            } else {
                $this->setAvailableIfNoActiveStay($resRoom->room_id, $reservation->id);
            }
        }
    }

    public function markNeedsPreparation(int $roomId): void
    {
        Room::where('id', $roomId)
            ->where('active', 1)
            ->where('roomStatus', '!=', self::ROOM_OUT_OF_SERVICE)
            ->update(['roomStatus' => self::ROOM_PREPARATION]);

        OccupancyBoardCache::bump();
    }

    public function roomHasActiveInHouseStay(int $roomId, ?int $excludeReservationId = null): bool
    {
        return $this->findActiveInHouseReservationId($roomId, $excludeReservationId) !== null;
    }

    public function findActiveInHouseReservationId(int $roomId, ?int $excludeReservationId = null): ?int
    {
        $row = ReservationRoom::where('room_id', $roomId)
            ->whereHas('reservation', function ($query) use ($excludeReservationId) {
                $query->where('reservation_status', Reservation::STATUS_CONFIRMED)
                    ->where('logedin', Reservation::LOGEDIN_IN_HOUSE);
                if ($excludeReservationId) {
                    $query->where('id', '!=', $excludeReservationId);
                }
            })
            ->first();

        return $row ? (int) $row->reservation_id : null;
    }

    /**
     * @throws \RuntimeException when the room is not ready for check-in
     */
    public function assertRoomReadyForCheckIn(Room $room, ?int $excludeReservationId = null): void
    {
        $status = (int) $room->roomStatus;
        $roomId = (int) $room->id;

        if ($status === self::ROOM_OUT_OF_SERVICE) {
            throw new \RuntimeException('Cannot check in — room is out of service.');
        }

        if ($status === self::ROOM_PREPARATION) {
            throw new \RuntimeException('Cannot check in — room needs cleaning first.');
        }

        $blockingReservationId = $this->findActiveInHouseReservationId($roomId, $excludeReservationId);
        if ($blockingReservationId !== null) {
            $label = $room->number ?: (string) $roomId;
            throw new \RuntimeException(
                "Cannot check in — room {$label} is occupied by reservation #{$blockingReservationId}."
            );
        }

        if ($status === self::ROOM_OCCUPIED) {
            $this->setAvailableIfNoActiveStay($roomId, $excludeReservationId);
            $room->refresh();
            $status = (int) $room->roomStatus;
        }

        if ($status !== self::ROOM_AVAILABLE) {
            throw new \RuntimeException('Cannot check in — room is not ready.');
        }
    }

    /**
     * @throws \RuntimeException when any assigned room is not ready for check-in
     */
    public function assertRoomsReadyForCheckIn(Reservation $reservation): void
    {
        $reservation->loadMissing('reservationRooms.room');

        foreach ($reservation->reservationRooms as $resRoom) {
            if (!$resRoom->room_id || !$resRoom->room) {
                continue;
            }

            $this->assertRoomReadyForCheckIn($resRoom->room, (int) $reservation->id);
        }
    }

    public function setAvailableIfNoActiveStay(int $roomId, ?int $excludeReservationId = null): void
    {
        $hasOverlap = ReservationRoom::where('room_id', $roomId)
            ->whereHas('reservation', function ($query) use ($excludeReservationId) {
                $query->where('reservation_status', Reservation::STATUS_CONFIRMED)
                    ->where('logedin', Reservation::LOGEDIN_IN_HOUSE);
                if ($excludeReservationId) {
                    $query->where('id', '!=', $excludeReservationId);
                }
            })
            ->exists();

        if (!$hasOverlap) {
            Room::where('id', $roomId)
                ->where('active', 1)
                ->whereNotIn('roomStatus', [self::ROOM_PREPARATION, self::ROOM_OUT_OF_SERVICE])
                ->update(['roomStatus' => self::ROOM_AVAILABLE]);

            OccupancyBoardCache::bump();
        }
    }
}
