<?php

namespace App\Services;

use App\Models\Hotel;
use App\Models\Reservation;
use Illuminate\Support\Facades\DB;

/**
 * Resolves which branch (hotel) a reservation or room belongs to. A
 * reservation's rooms are guaranteed to share one hotel (enforced at booking
 * time — see ReservationController::makeReservation), so the first
 * resolvable room decides it. Falls back to the oldest hotel for
 * pre-multi-branch data that has nothing else to resolve from.
 */
class HotelResolverService
{
    public function forReservation(Reservation $reservation): int
    {
        if (! $reservation->relationLoaded('reservationRooms')) {
            $reservation->load('reservationRooms.room.building');
        }

        $hotelId = $reservation->reservationRooms
            ->map(fn ($rr) => $rr->room?->building?->hotel_id)
            ->filter()
            ->first();

        return $hotelId !== null ? (int) $hotelId : $this->defaultHotelId();
    }

    public function forRoomId(?int $roomId): int
    {
        if ($roomId === null) {
            return $this->defaultHotelId();
        }

        $hotelId = DB::table('rooms')
            ->join('buildings', 'rooms.building_id', '=', 'buildings.id')
            ->where('rooms.id', $roomId)
            ->value('buildings.hotel_id');

        return $hotelId !== null ? (int) $hotelId : $this->defaultHotelId();
    }

    public function defaultHotelId(): int
    {
        return (int) (Hotel::query()->orderBy('id')->value('id') ?? 1);
    }
}
