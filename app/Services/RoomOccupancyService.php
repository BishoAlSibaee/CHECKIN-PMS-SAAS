<?php

namespace App\Services;

use App\Models\Reservation;
use App\Models\ReservationPay;
use App\Models\ReservationRoom;
use App\Models\Room;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class RoomOccupancyService
{
    /**
     * @param  array{building_id?: int, floor_id?: int, suite_id?: int, occupancy_status?: string, search?: string}  $filters
     */
    public function buildBoard(Carbon $date, array $filters = []): array
    {
        $dateStr = $date->toDateString();

        $query = Room::with(['roomType', 'building', 'floor', 'suite.floor'])
            ->where('active', 1);

        if (! empty($filters['building_id'])) {
            $query->where('building_id', (int) $filters['building_id']);
        }
        if (! empty($filters['floor_id'])) {
            $query->where('floor_id', (int) $filters['floor_id']);
        }
        if (! empty($filters['suite_id'])) {
            $query->where('suite_id', (int) $filters['suite_id']);
        }
        if (! empty($filters['search'])) {
            $search = $filters['search'];
            $query->where('number', 'like', '%'.$search.'%');
        }

        $rooms = $query->orderBy('building_id')->orderBy('floor_id')->orderBy('number')->get();
        $roomIds = $rooms->pluck('id');

        $reservationByRoom = $this->loadReservationsByRoom($roomIds, $dateStr);

        $items = [];
        $summary = $this->emptySummary();

        foreach ($rooms as $room) {
            $reservation = $reservationByRoom->get($room->id);
            $occupancyStatus = $this->resolveOccupancyStatus($room, $reservation, $dateStr);

            if (! empty($filters['occupancy_status']) && $filters['occupancy_status'] !== $occupancyStatus) {
                continue;
            }

            $summary['total']++;
            $summary[$occupancyStatus] = ($summary[$occupancyStatus] ?? 0) + 1;

            $items[] = $this->formatRoomPayload($room, $occupancyStatus, $reservation, $dateStr);
        }

        $summary['occupancy_rate'] = $summary['total'] > 0
            ? round((($summary['in_house'] ?? 0) + ($summary['reserved'] ?? 0)) / $summary['total'] * 100, 1)
            : 0;

        return [
            'date' => $dateStr,
            'summary' => $summary,
            'rooms' => $items,
        ];
    }

    /**
     * Summary counts only (for dashboard) — lightweight, no room payloads.
     */
    public function summaryForDate(Carbon $date): array
    {
        return $this->summaryCountsForDate($date);
    }

    /**
     * Occupancy summary without building full board payloads (faster for reports/dashboard).
     */
    public function summaryCountsForDate(Carbon $date): array
    {
        $dateStr = $date->toDateString();

        $rooms = Room::query()
            ->where('active', 1)
            ->get(['id', 'roomStatus', 'active']);

        $reservationByRoom = $this->loadReservationsByRoom($rooms->pluck('id'), $dateStr);

        $summary = $this->emptySummary();

        foreach ($rooms as $room) {
            $reservation = $reservationByRoom->get($room->id);
            $occupancyStatus = $this->resolveOccupancyStatus($room, $reservation, $dateStr);

            $summary['total']++;
            $summary[$occupancyStatus] = ($summary[$occupancyStatus] ?? 0) + 1;
        }

        $summary['occupancy_rate'] = $summary['total'] > 0
            ? round((($summary['in_house'] ?? 0) + ($summary['reserved'] ?? 0)) / $summary['total'] * 100, 1)
            : 0;

        return $summary;
    }

    /**
     * Per-day in_house and vacant counts for a date range (single room/reservation load).
     *
     * @return array<string, array{in_house: int, vacant: int}>
     */
    public function dailyInHouseVacantForRange(Carbon $start, Carbon $end): array
    {
        $rooms = Room::query()
            ->where('active', 1)
            ->get(['id', 'roomStatus', 'active']);

        if ($rooms->isEmpty()) {
            return [];
        }

        $reservationRooms = $this->loadReservationRoomsForRange(
            $rooms->pluck('id'),
            $start,
            $end
        );

        $byDate = [];

        for ($day = $start->copy(); $day->lte($end); $day->addDay()) {
            $dateStr = $day->toDateString();
            $inHouse = 0;
            $vacant = 0;

            foreach ($rooms as $room) {
                $reservation = $this->pickReservationForRoomOnDate(
                    $reservationRooms,
                    (int) $room->id,
                    $dateStr
                );
                $status = $this->resolveOccupancyStatus($room, $reservation, $dateStr);

                if ($status === 'in_house' || $status === 'check_out_today') {
                    $inHouse++;
                } elseif ($status === 'vacant') {
                    $vacant++;
                }
            }

            $byDate[$dateStr] = [
                'in_house' => $inHouse,
                'vacant' => $vacant,
            ];
        }

        return $byDate;
    }

    public function checkInsToday(Carbon $date): int
    {
        return Reservation::where('reservation_status', 1)
            ->whereDate('start_date', $date->toDateString())
            ->count();
    }

    public function checkOutsToday(Carbon $date): int
    {
        return $this->checkoutsDueOnDateQuery($date)->count();
    }

    public function overdueCheckoutsCount(Carbon $date): int
    {
        return $this->overdueCheckoutsQuery($date)->count();
    }

    public function pendingCheckoutsCount(Carbon $date): int
    {
        return $this->pendingCheckoutsQuery($date)->count();
    }

    public function arrivalsToday(Carbon $date): array
    {
        return $this->formatMovementList(
            Reservation::with(['client', 'reservationRooms.room'])
                ->where('reservation_status', 1)
                ->whereDate('start_date', $date->toDateString())
                ->orderBy('start_date')
                ->get()
        );
    }

    public function departuresToday(Carbon $date): array
    {
        return $this->formatMovementList(
            $this->pendingCheckoutsQuery($date)
                ->with(['client', 'reservationRooms.room', 'payments'])
                ->orderBy('expire_date')
                ->get()
        );
    }

    private function pendingCheckoutsQuery(Carbon $date)
    {
        return Reservation::query()
            ->where('reservation_status', Reservation::STATUS_CONFIRMED)
            ->where('logedin', Reservation::LOGEDIN_IN_HOUSE)
            ->where('expire_date', '<=', $date->toDateString());
    }

    private function checkoutsDueOnDateQuery(Carbon $date)
    {
        return Reservation::query()
            ->where('reservation_status', Reservation::STATUS_CONFIRMED)
            ->where('logedin', Reservation::LOGEDIN_IN_HOUSE)
            ->whereDate('expire_date', $date->toDateString());
    }

    private function overdueCheckoutsQuery(Carbon $date)
    {
        return Reservation::query()
            ->where('reservation_status', Reservation::STATUS_CONFIRMED)
            ->where('logedin', Reservation::LOGEDIN_IN_HOUSE)
            ->where('expire_date', '<', $date->toDateString());
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function pendingCheckouts(Carbon $date): array
    {
        return $this->departuresToday($date);
    }

    private function formatMovementList($reservations): array
    {
        return $reservations->map(function (Reservation $r) {
            $room = $r->reservationRooms->first()?->room;

            return [
                'reservation_id' => $r->id,
                'guest' => trim(($r->client->first_name ?? '').' '.($r->client->last_name ?? '')),
                'room' => $room?->number ?? '—',
                'start_date' => $r->start_date,
                'expire_date' => $r->expire_date,
                'logedin' => (int) $r->logedin,
                'balance_due' => $r->balanceDue(),
                'is_overdue' => $r->expire_date < Carbon::today()->toDateString(),
            ];
        })->values()->all();
    }

    private function loadReservationsByRoom(Collection $roomIds, string $dateStr): Collection
    {
        if ($roomIds->isEmpty()) {
            return collect();
        }

        $reservationRooms = ReservationRoom::with([
            'reservation.client',
            'reservation.payments',
        ])
            ->whereIn('room_id', $roomIds)
            ->whereHas('reservation', function ($q) use ($dateStr) {
                $q->whereIn('reservation_status', [1, 2])
                    ->where(function ($q2) use ($dateStr) {
                        $q2->where(function ($q3) use ($dateStr) {
                            $q3->where('start_date', '<=', $dateStr)
                                ->where('expire_date', '>', $dateStr);
                        })
                            ->orWhere('start_date', $dateStr)
                            ->orWhere('expire_date', $dateStr)
                            ->orWhere(function ($q4) use ($dateStr) {
                                $q4->where('logedin', Reservation::LOGEDIN_IN_HOUSE)
                                    ->where('start_date', '<=', $dateStr)
                                    ->where('expire_date', '<', $dateStr);
                            });
                    });
            })
            ->get();

        $byRoom = collect();

        foreach ($reservationRooms as $reservationRoom) {
            $roomId = $reservationRoom->room_id;
            $existing = $byRoom->get($roomId);
            $reservation = $reservationRoom->reservation;

            if (! $reservation) {
                continue;
            }

            if (! $existing || $this->reservationPriority($reservation, $dateStr)
                > $this->reservationPriority($existing, $dateStr)) {
                $byRoom->put($roomId, $reservation);
            }
        }

        return $byRoom;
    }

    private function loadReservationRoomsForRange(Collection $roomIds, Carbon $start, Carbon $end): Collection
    {
        if ($roomIds->isEmpty()) {
            return collect();
        }

        $startStr = $start->toDateString();
        $endStr = $end->toDateString();

        return ReservationRoom::with([
            'reservation.client',
            'reservation.payments',
        ])
            ->whereIn('room_id', $roomIds)
            ->whereHas('reservation', function ($q) use ($startStr, $endStr) {
                $q->whereIn('reservation_status', [1, 2])
                    ->where(function ($q2) use ($startStr, $endStr) {
                        $q2->where(function ($q3) use ($startStr, $endStr) {
                            $q3->where('start_date', '<=', $endStr)
                                ->where('expire_date', '>=', $startStr);
                        })
                            ->orWhere(function ($q4) use ($startStr) {
                                $q4->where('logedin', Reservation::LOGEDIN_IN_HOUSE)
                                    ->where('start_date', '<=', $startStr);
                            });
                    });
            })
            ->get();
    }

    private function pickReservationForRoomOnDate(Collection $reservationRooms, int $roomId, string $dateStr): ?Reservation
    {
        $best = null;

        foreach ($reservationRooms as $reservationRoom) {
            if ((int) $reservationRoom->room_id !== $roomId) {
                continue;
            }

            $reservation = $reservationRoom->reservation;
            if (! $reservation) {
                continue;
            }

            if (! $this->reservationTouchesDate($reservation, $dateStr)) {
                continue;
            }

            if (! $best || $this->reservationPriority($reservation, $dateStr)
                > $this->reservationPriority($best, $dateStr)) {
                $best = $reservation;
            }
        }

        return $best;
    }

    private function reservationTouchesDate(Reservation $reservation, string $dateStr): bool
    {
        $start = $reservation->start_date;
        $expire = $reservation->expire_date;
        $checkedIn = (int) $reservation->logedin === Reservation::LOGEDIN_IN_HOUSE;

        if ($start <= $dateStr && $expire >= $dateStr) {
            return true;
        }

        if ($checkedIn && $start <= $dateStr && $expire < $dateStr) {
            return true;
        }

        return false;
    }

    private function reservationPriority(Reservation $reservation, string $dateStr): int
    {
        $status = $this->resolveOccupancyStatus(
            (object) ['roomStatus' => 1, 'active' => 1],
            $reservation,
            $dateStr
        );

        return match ($status) {
            'in_house' => 7,
            'check_out_today' => 6,
            'check_in_today' => 5,
            'reserved' => 4,
            'pending' => 3,
            'vacant' => 1,
            default => 0,
        };
    }

    private function resolveOccupancyStatus($room, ?Reservation $reservation, string $dateStr): string
    {
        $roomStatus = is_object($room) && isset($room->roomStatus) ? (int) $room->roomStatus : 1;
        $active = is_object($room) && isset($room->active) ? (int) $room->active : 1;

        if (! $active || $roomStatus === 4) {
            return 'out_of_service';
        }

        if ($roomStatus === 3) {
            return 'preparation';
        }

        if (! $reservation) {
            if ($roomStatus === 2) {
                return 'preparation';
            }

            return 'vacant';
        }

        if ((int) $reservation->reservation_status === Reservation::STATUS_PENDING_PAYMENT) {
            return 'pending';
        }

        if (Reservation::isCancelled((int) $reservation->reservation_status)) {
            return 'vacant';
        }

        $start = $reservation->start_date;
        $expire = $reservation->expire_date;
        $overlapsNight = $start <= $dateStr && $expire > $dateStr;
        $checkedIn = (int) $reservation->logedin === Reservation::LOGEDIN_IN_HOUSE;

        if ($checkedIn && $start <= $dateStr && ($overlapsNight || $expire <= $dateStr)) {
            if ($expire === $dateStr) {
                return 'check_out_today';
            }

            return 'in_house';
        }

        if ($start === $dateStr) {
            return 'check_in_today';
        }

        if ($expire === $dateStr) {
            return 'check_out_today';
        }

        if ($overlapsNight) {
            return 'reserved';
        }

        return 'vacant';
    }

    private function formatRoomPayload(Room $room, string $occupancyStatus, ?Reservation $reservation, string $dateStr): array
    {
        $payload = [
            'id' => $room->id,
            'number' => $room->number,
            'capacity' => $room->capacity,
            'operational_status' => (int) $room->roomStatus,
            'operational_status_label' => $this->operationalLabel((int) $room->roomStatus),
            'occupancy_status' => $occupancyStatus,
            'room_type_id' => $room->room_type_id,
            'room_type_name' => $room->roomType?->name_en ?? $room->roomType?->name_ar ?? '—',
            'building_id' => $room->building_id,
            'building_name' => $room->building?->name ?? null,
            'floor_id' => $room->floor_id,
            'floor_name' => $room->floor ? 'Floor '.($room->floor->number ?? $room->floor_id) : null,
            'floor_number' => $room->floor?->number ?? (string) $room->floor_id,
            'suite_id' => $room->suite_id,
            'suite_name' => $this->formatSuiteLabel($room->suite?->number),
            'suite_floor_id' => $room->suite?->floor_id ?? $room->floor_id,
            'suite_floor_number' => $room->suite?->floor?->number ?? $room->floor?->number,
            'reservation' => null,
        ];

        if ($reservation) {
            $paid = (float) $reservation->payments
                ->where('type', ReservationPay::TYPE_PAYMENT)
                ->sum('pay');
            $refunded = (float) $reservation->payments
                ->where('type', ReservationPay::TYPE_REFUND)
                ->sum('pay');
            $paidNet = $paid - $refunded;
            $total = (float) $reservation->total;
            $expire = Carbon::parse($reservation->expire_date);
            $viewDate = Carbon::parse($dateStr);
            $nightsRemaining = max(0, $viewDate->diffInDays($expire, false));

            $client = $reservation->client;
            $payload['reservation'] = [
                'reservation_id' => $reservation->id,
                'client_name' => $client
                    ? trim(($client->first_name ?? '').' '.($client->last_name ?? ''))
                    : '—',
                'client_phone' => $client->mobile ?? null,
                'start_date' => $reservation->start_date,
                'expire_date' => $reservation->expire_date,
                'nights_remaining' => $nightsRemaining,
                'reservation_status' => (int) $reservation->reservation_status,
                'logedin' => (int) $reservation->logedin,
                'base_price' => round((float) $reservation->base_price, 2),
                'total' => round($total, 2),
                'paid_amount' => round($paidNet, 2),
                'balance_due' => round(max(0, $total - $paidNet), 2),
            ];
        }

        return $payload;
    }

    private function operationalLabel(int $status): string
    {
        return match ($status) {
            1 => 'available',
            2 => 'occupied',
            3 => 'preparation',
            4 => 'out_of_service',
            default => 'unknown',
        };
    }

    private function formatSuiteLabel(?string $number): ?string
    {
        if ($number === null || $number === '') {
            return null;
        }

        $trimmed = trim($number);
        if (str_starts_with(strtolower($trimmed), 'suite')) {
            return $trimmed;
        }

        return 'Suite '.$trimmed;
    }

    private function emptySummary(): array
    {
        return [
            'total' => 0,
            'vacant' => 0,
            'reserved' => 0,
            'in_house' => 0,
            'pending' => 0,
            'check_in_today' => 0,
            'check_out_today' => 0,
            'preparation' => 0,
            'out_of_service' => 0,
            'occupancy_rate' => 0,
        ];
    }
}
