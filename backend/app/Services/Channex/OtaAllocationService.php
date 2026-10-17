<?php

namespace App\Services\Channex;

use App\Models\OtaRatePlan;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\RoomTypeOtaAllocation;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * How many rooms of each type a hotel has chosen to release to OTA channels
 * (Booking.com, Expedia, ... via Channex), and how much of that release is
 * actually still sellable per date. Allocation is a manual ceiling staff set
 * per (hotel, room_type) — not an automatic share of physical inventory —
 * and is shared across every connected channel (no per-channel split).
 */
class OtaAllocationService
{
    /**
     * @throws RuntimeException  if releasing rooms (allocatedRooms > 0) for a
     *                           room type that has no active OtaRatePlan —
     *                           ChannexAriPushService::pushRates() silently
     *                           skips pushing any rate for such a room type,
     *                           so it would show as "available" on the OTA
     *                           with no price ever attached to sell it at.
     */
    public function setAllocation(int $hotelId, int $roomTypeId, int $allocatedRooms, ?int $userId): RoomTypeOtaAllocation
    {
        $allocatedRooms = max(0, $allocatedRooms);

        if ($allocatedRooms > 0 && ! $this->hasActiveRatePlan($hotelId, $roomTypeId)) {
            throw new RuntimeException(
                'Add at least one active rate plan for this room type before releasing rooms to OTA channels — '.
                'otherwise the OTA would show it as available with no price attached.'
            );
        }

        return RoomTypeOtaAllocation::updateOrCreate(
            ['hotel_id' => $hotelId, 'room_type_id' => $roomTypeId],
            ['allocated_rooms' => $allocatedRooms, 'updated_by' => $userId]
        );
    }

    public function hasActiveRatePlan(int $hotelId, int $roomTypeId): bool
    {
        return OtaRatePlan::where('hotel_id', $hotelId)
            ->where('room_type_id', $roomTypeId)
            ->where('is_active', true)
            ->exists();
    }

    /**
     * One row per room type that exists in this hotel, with the current
     * allocation (0 if never set), total physical rooms of that type, and
     * how many of the allocated rooms are booked via an OTA channel from
     * today onward — the admin screen's "release more?" signal.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function listForHotel(int $hotelId): Collection
    {
        $roomTypeIds = Room::query()
            ->join('buildings', 'buildings.id', '=', 'rooms.building_id')
            ->where('buildings.hotel_id', $hotelId)
            ->where('rooms.active', 1)
            ->distinct()
            ->pluck('rooms.room_type_id');

        $roomTypes = RoomType::whereIn('id', $roomTypeIds)->get()->keyBy('id');

        $totalByType = Room::query()
            ->join('buildings', 'buildings.id', '=', 'rooms.building_id')
            ->where('buildings.hotel_id', $hotelId)
            ->where('rooms.active', 1)
            ->selectRaw('rooms.room_type_id, count(*) as total')
            ->groupBy('rooms.room_type_id')
            ->pluck('total', 'room_type_id');

        $allocations = RoomTypeOtaAllocation::where('hotel_id', $hotelId)->get()->keyBy('room_type_id');

        $otaBookedByType = $this->otaBookedCountsFromToday($hotelId, $roomTypeIds->all());

        // Loaded here (not per-row) so the merged allocation+rate-plan admin
        // screen needs a single request, not a second round-trip to the
        // rate-plans endpoint just to know whether a room type is covered.
        $ratePlansByType = OtaRatePlan::where('hotel_id', $hotelId)
            ->whereIn('room_type_id', $roomTypeIds)
            ->with('channexMapping')
            ->orderByDesc('is_active')
            ->orderBy('name_en')
            ->get()
            ->groupBy('room_type_id');

        return $roomTypes->map(function (RoomType $roomType) use ($totalByType, $allocations, $otaBookedByType, $ratePlansByType) {
            $allocation = $allocations->get($roomType->id);
            $ratePlans = $ratePlansByType->get($roomType->id, collect());

            return [
                'room_type_id' => $roomType->id,
                'room_type_name_en' => $roomType->name_en,
                'room_type_name_ar' => $roomType->name_ar,
                'total_rooms' => (int) ($totalByType[$roomType->id] ?? 0),
                'allocated_rooms' => (int) ($allocation->allocated_rooms ?? 0),
                'ota_booked' => (int) ($otaBookedByType[$roomType->id] ?? 0),
                'rate_plans' => $ratePlans->values(),
                'has_active_rate_plan' => $ratePlans->contains(fn (OtaRatePlan $plan) => $plan->is_active),
            ];
        })->values();
    }

    /**
     * Per-date sellable count for one room type — what Phase 4's ARI push
     * will send to Channex. Never exceeds the manual allocation ceiling,
     * never exceeds true physical availability either.
     *
     * @return array<string, int> date (Y-m-d) => available count
     */
    public function availabilityForDateRange(int $hotelId, int $roomTypeId, Carbon $from, Carbon $to): array
    {
        $allocatedRooms = (int) (RoomTypeOtaAllocation::where('hotel_id', $hotelId)
            ->where('room_type_id', $roomTypeId)
            ->value('allocated_rooms') ?? 0);

        $roomIds = Room::query()
            ->join('buildings', 'buildings.id', '=', 'rooms.building_id')
            ->where('buildings.hotel_id', $hotelId)
            ->where('rooms.room_type_id', $roomTypeId)
            ->where('rooms.active', 1)
            ->pluck('rooms.id');

        $totalRooms = $roomIds->count();
        if ($totalRooms === 0) {
            return [];
        }

        $blockingReservations = \App\Models\ReservationRoom::query()
            ->whereIn('room_id', $roomIds)
            ->whereHas('reservation', fn ($q) => $q
                ->whereNotIn('reservation_status', Reservation::nonBlockingInventoryStatuses())
                ->where('start_date', '<', $to->toDateString())
                ->where('expire_date', '>', $from->toDateString()))
            ->with(['reservation:id,start_date,expire_date,reservation_source_id'])
            ->get();

        $otaSourceIds = \App\Models\Reservation_source::where('is_ota_channel', true)->pluck('id')->all();

        $result = [];
        foreach (CarbonPeriod::create($from, $to->copy()->subDay()) as $date) {
            $dateStr = $date->toDateString();

            $blockedToday = $blockingReservations->filter(fn ($rr) => $rr->reservation
                && $rr->reservation->start_date <= $dateStr
                && $rr->reservation->expire_date > $dateStr);

            $blockedCount = $blockedToday->count();
            $otaBookedCount = $blockedToday->filter(fn ($rr) => in_array($rr->reservation->reservation_source_id, $otaSourceIds, true))->count();

            $physicalAvailable = max(0, $totalRooms - $blockedCount);
            $allocationHeadroom = max(0, $allocatedRooms - $otaBookedCount);

            $result[$dateStr] = max(0, min($allocationHeadroom, $physicalAvailable));
        }

        return $result;
    }

    /** @param  list<int>  $roomTypeIds
     * @return array<int, int> room_type_id => count */
    private function otaBookedCountsFromToday(int $hotelId, array $roomTypeIds): array
    {
        if ($roomTypeIds === []) {
            return [];
        }

        $today = Carbon::today()->toDateString();
        $otaSourceIds = \App\Models\Reservation_source::where('is_ota_channel', true)->pluck('id');
        if ($otaSourceIds->isEmpty()) {
            return [];
        }

        return \App\Models\ReservationRoom::query()
            ->join('rooms', 'rooms.id', '=', 'reservation_rooms.room_id')
            ->join('reservations', 'reservations.id', '=', 'reservation_rooms.reservation_id')
            ->join('buildings', 'buildings.id', '=', 'rooms.building_id')
            ->where('buildings.hotel_id', $hotelId)
            ->whereIn('rooms.room_type_id', $roomTypeIds)
            ->whereIn('reservations.reservation_source_id', $otaSourceIds)
            ->whereNotIn('reservations.reservation_status', Reservation::nonBlockingInventoryStatuses())
            ->where('reservations.expire_date', '>=', $today)
            ->selectRaw('rooms.room_type_id, count(distinct reservations.id) as cnt')
            ->groupBy('rooms.room_type_id')
            ->pluck('cnt', 'room_type_id')
            ->all();
    }
}
