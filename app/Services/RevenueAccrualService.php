<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Reservation;
use App\Models\ReservationDailyCharge;
use App\Models\ReservationPay;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class RevenueAccrualService
{
    private const TAX_RATE = 0.15;

    /**
     * Accrual revenue for a date range from stored daily charges.
     */
    public function calculate(
        string $scope,
        ?int $entityId,
        Carbon $startDate,
        Carbon $endDate,
        bool $includeDetails = false
    ): array {
        $charges = $this->queryCharges($scope, $entityId, $startDate, $endDate)->get();

        $clientNames = $this->loadClientNamesById($charges);
        $allocated = $this->allocateCharges($charges, $clientNames);

        $nightlyBase = 0.0;
        $monthlyBase = 0.0;
        $details = [];

        foreach ($allocated as $row) {
            if ((int) $row->rent_type === 1) {
                $monthlyBase += $row->period_base;
            } else {
                $nightlyBase += $row->period_base;
            }

            if ($includeDetails) {
                $details[] = [
                    'charge_date' => $row->charge_date,
                    'reservation_id' => $row->reservation_id,
                    'guest' => $row->guest ?? '—',
                    'room_id' => $row->room_id,
                    'room_number' => $row->room_number,
                    'base_amount' => round($row->period_base, 2),
                    'discount_allocated' => round($row->discount_allocated, 2),
                    'extras_allocated' => round($row->extras_allocated, 2),
                    'penalties_allocated' => round($row->penalties_allocated, 2),
                    'subtotal' => round($row->period_subtotal, 2),
                    'tax' => round($row->period_tax, 2),
                    'revenue' => round($row->period_revenue, 2),
                    'is_in_plan' => (bool) $row->is_in_plan,
                    'is_peak_day' => (bool) $row->is_peak_day,
                    'price_source' => $row->price_source,
                ];
            }
        }

        $totalBase = $nightlyBase + $monthlyBase;
        $allocatedCollection = collect($allocated);
        $totalSubtotal = $allocatedCollection->sum('period_subtotal');
        $totalTax = $allocatedCollection->sum('period_tax');
        $totalRevenue = $allocatedCollection->sum('period_revenue');

        $reservationIds = $charges->pluck('reservation_id')->unique();

        return [
            'current' => [
                'nightly' => round($nightlyBase, 2),
                'monthly' => round($monthlyBase, 2),
                'total_base' => round($totalBase, 2),
                'subtotal' => round($totalSubtotal, 2),
                'tax' => round($totalTax, 2),
                'total' => round($totalRevenue, 2),
                'count' => $charges->count(),
                'reservation_count' => $reservationIds->count(),
            ],
            'details' => $includeDetails ? $details : null,
        ];
    }

    private function queryCharges(string $scope, ?int $entityId, Carbon $startDate, Carbon $endDate)
    {
        $rangeStart = $startDate->toDateString();
        $recognizedEnd = $endDate->copy()->startOfDay();
        $today = Carbon::today()->startOfDay();
        if ($recognizedEnd->gt($today)) {
            $recognizedEnd = $today;
        }
        $rangeEnd = $recognizedEnd->toDateString();

        $query = ReservationDailyCharge::query()
            ->select([
                'reservation_daily_charges.*',
                'reservations.discount',
                'reservations.classification_discount',
                'reservations.extras',
                'reservations.penalties',
                'reservations.base_price as reservation_base_price',
                'reservations.total as reservation_total',
                'rooms.number as room_number',
                'reservations.client_id',
            ])
            ->join('reservations', 'reservation_daily_charges.reservation_id', '=', 'reservations.id')
            ->join('rooms', 'reservation_daily_charges.room_id', '=', 'rooms.id')
            ->where(function ($eligible) {
                $paymentType = ReservationPay::TYPE_PAYMENT;
                $refundType = ReservationPay::TYPE_REFUND;

                $eligible->where('reservations.reservation_status', Reservation::STATUS_CONFIRMED)
                    ->orWhere(function ($pending) use ($paymentType, $refundType) {
                        $pending->where('reservations.reservation_status', Reservation::STATUS_PENDING_PAYMENT)
                            ->whereRaw(
                                '(reservations.total - COALESCE((
                                    SELECT SUM(CASE WHEN rp.type = ? THEN rp.pay WHEN rp.type = ? THEN -rp.pay ELSE 0 END)
                                    FROM reservation_pay rp
                                    WHERE rp.reservation_id = reservations.id
                                ), 0)) <= ?',
                                [$paymentType, $refundType, 0.005]
                            );
                    });
            });

        if ($rangeStart > $rangeEnd) {
            $query->whereRaw('1 = 0');
        } else {
            $query->whereBetween('reservation_daily_charges.charge_date', [$rangeStart, $rangeEnd]);
        }

        $this->applyScope($query, $scope, $entityId);

        return $query;
    }

    private function applyScope($query, string $scope, ?int $entityId): void
    {
        if ($scope === 'total' || $entityId === null) {
            return;
        }

        match ($scope) {
            'room' => $query->where('rooms.id', $entityId),
            'building' => $query->where('rooms.building_id', $entityId),
            'floor' => $query->where('rooms.floor_id', $entityId),
            'suite' => $query->where(function ($q) use ($entityId) {
                $q->where('rooms.suite_id', $entityId)
                    ->orWhereExists(function ($sub) use ($entityId) {
                        $sub->select(DB::raw(1))
                            ->from('reservation_rooms')
                            ->whereColumn('reservation_rooms.id', 'reservation_daily_charges.reservation_room_id')
                            ->where('reservation_rooms.suite_id', $entityId);
                    });
            }),
            'roomtype' => $query->where('rooms.room_type_id', $entityId),
            default => null,
        };
    }

    /**
     * @return array<int, object>
     */
    /**
     * @return array<int, string>
     */
    private function loadClientNamesById(Collection $charges): array
    {
        $clientIds = $charges->pluck('client_id')->unique()->filter()->values();
        if ($clientIds->isEmpty()) {
            return [];
        }

        return Client::query()
            ->whereIn('id', $clientIds)
            ->get()
            ->mapWithKeys(fn (Client $client) => [
                $client->id => trim(($client->first_name ?? '').' '.($client->last_name ?? '')) ?: '—',
            ])
            ->all();
    }

    /**
     * @param  array<int, string>  $clientNames
     * @return array<int, object>
     */
    private function allocateCharges(Collection $charges, array $clientNames = []): array
    {
        if ($charges->isEmpty()) {
            return [];
        }

        $reservationTotals = ReservationDailyCharge::query()
            ->whereIn('reservation_id', $charges->pluck('reservation_id')->unique())
            ->selectRaw('reservation_id, SUM(base_amount) as total_base')
            ->groupBy('reservation_id')
            ->pluck('total_base', 'reservation_id');

        $result = [];

        foreach ($charges as $charge) {
            $reservationTotalBase = (float) ($reservationTotals[$charge->reservation_id] ?? 0);
            $weight = $reservationTotalBase > 0
                ? (float) $charge->base_amount / $reservationTotalBase
                : 0;

            $discountAlloc = ((float) $charge->classification_discount + (float) $charge->discount) * $weight;
            $extrasAlloc = (float) $charge->extras * $weight;
            $penaltiesAlloc = (float) $charge->penalties * $weight;

            $periodBase = (float) $charge->base_amount;
            $periodSubtotal = $periodBase - $discountAlloc + $extrasAlloc + $penaltiesAlloc;
            $periodTax = round($periodSubtotal * self::TAX_RATE, 2);
            $periodRevenue = $periodSubtotal + $periodTax;
            $guest = $clientNames[(int) $charge->client_id] ?? '—';

            $result[] = (object) [
                'charge_date' => $charge->charge_date->format('Y-m-d'),
                'reservation_id' => $charge->reservation_id,
                'guest' => $guest,
                'room_id' => $charge->room_id,
                'room_number' => $charge->room_number,
                'rent_type' => $charge->rent_type,
                'is_in_plan' => $charge->is_in_plan,
                'is_peak_day' => $charge->is_peak_day,
                'price_source' => $charge->price_source,
                'period_base' => $periodBase,
                'discount_allocated' => $discountAlloc,
                'extras_allocated' => $extrasAlloc,
                'penalties_allocated' => $penaltiesAlloc,
                'period_subtotal' => $periodSubtotal,
                'period_tax' => $periodTax,
                'period_revenue' => $periodRevenue,
            ];
        }

        return $result;
    }

    /**
     * Accrual revenue totals grouped by charge date (single DB read for the range).
     *
     * @return array<string, float> date => revenue
     */
    public function dailyRevenueTotals(Carbon $startDate, Carbon $endDate): array
    {
        $charges = $this->queryCharges('total', null, $startDate, $endDate)->get();
        $clientNames = $this->loadClientNamesById($charges);
        $allocated = $this->allocateCharges($charges, $clientNames);

        $byDate = [];
        foreach ($allocated as $row) {
            $day = $row->charge_date;
            $byDate[$day] = ($byDate[$day] ?? 0.0) + (float) $row->period_revenue;
        }

        foreach ($byDate as $day => $total) {
            $byDate[$day] = round($total, 2);
        }

        ksort($byDate);

        return $byDate;
    }

    public function persistDailyCharges(
        int $reservationId,
        int $reservationRoomId,
        int $roomId,
        int $rentType,
        array $lines
    ): void {
        ReservationDailyCharge::where('reservation_room_id', $reservationRoomId)->delete();

        foreach ($lines as $line) {
            ReservationDailyCharge::create([
                'reservation_id' => $reservationId,
                'reservation_room_id' => $reservationRoomId,
                'room_id' => $roomId,
                'charge_date' => $line['date'],
                'base_amount' => $line['base_amount'],
                'is_peak_day' => $line['is_peak_day'],
                'is_in_plan' => $line['is_in_plan'],
                'price_source' => $line['price_source'],
                'rent_type' => $rentType,
            ]);
        }
    }
}
