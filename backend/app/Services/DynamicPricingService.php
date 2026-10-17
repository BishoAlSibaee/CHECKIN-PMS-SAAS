<?php

namespace App\Services;

use App\Models\DynamicPricingRule;
use App\Models\Reservation;
use App\Models\ReservationRoom;
use App\Models\Room;
use App\Models\RoomType;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Per-room-type percentage adjustments (occupancy + lead time) applied on
 * top of a room type's own Min/Max base rate — never a replacement pricing
 * formula. See PricingEngine::buildDailyRentalBreakdown(), which skips
 * pricing-plan resolution entirely for a room type with dynamic pricing
 * enabled and calls here instead.
 */
class DynamicPricingService
{
    /**
     * One query for the whole stay, not one per night — avoids N+1 on
     * multi-night stays. Occupancy here uses the same "is this room really
     * blocking inventory" definition as ReservationController::isRoomAvailable()
     * (ReservationRoom::STATUS_ACTIVE + Reservation::nonBlockingInventoryStatuses()),
     * not RoomOccupancyService's board-display semantics.
     *
     * @return array<string, float> date string => occupancy percentage (0-100)
     */
    public function occupancyRatesForRange(int $roomTypeId, Carbon $start, Carbon $end): array
    {
        $totalRooms = Room::where('room_type_id', $roomTypeId)->where('active', 1)->count();

        $rates = [];
        if ($totalRooms === 0) {
            for ($date = $start->copy(); $date->lt($end); $date->addDay()) {
                $rates[$date->toDateString()] = 0.0;
            }

            return $rates;
        }

        $startStr = $start->toDateString();
        $endStr = $end->toDateString();

        $bookings = ReservationRoom::query()
            ->whereHas('room', fn ($q) => $q->where('room_type_id', $roomTypeId))
            ->where('status', ReservationRoom::STATUS_ACTIVE)
            ->whereHas('reservation', function ($q) use ($startStr, $endStr) {
                $q->whereNotIn('reservation_status', Reservation::nonBlockingInventoryStatuses())
                    ->where('start_date', '<', $endStr)
                    ->where('expire_date', '>', $startStr);
            })
            ->with('reservation:id,start_date,expire_date')
            ->get();

        for ($date = $start->copy(); $date->lt($end); $date->addDay()) {
            $dateStr = $date->toDateString();
            $count = $bookings->filter(function (ReservationRoom $booking) use ($dateStr) {
                $res = $booking->reservation;

                return $res && $res->start_date <= $dateStr && $res->expire_date > $dateStr;
            })->count();

            $rates[$dateStr] = round(($count / $totalRooms) * 100, 2);
        }

        return $rates;
    }

    /**
     * First matching rule wins; 0% when no rule matches a value.
     */
    public function adjustmentPercentFor(float $value, Collection $rules): float
    {
        foreach ($rules as $rule) {
            if ($rule->matches($value)) {
                return (float) $rule->adjustment_percent;
            }
        }

        return 0.0;
    }

    /**
     * Combines occupancy% + lead-time% additively, applies to the base
     * amount, then clamps to [Min_daily_price, Max_daily_price * 2] so a
     * misconfigured rule can't produce a free room or an absurd rate.
     *
     * @return array{amount: float, source: string}
     */
    public function applyDynamicPricing(
        float $baseAmount,
        RoomType $roomType,
        float $occupancyRate,
        int $leadTimeDays,
        Collection $occupancyRules,
        Collection $leadTimeRules
    ): array {
        $occupancyPercent = $this->adjustmentPercentFor($occupancyRate, $occupancyRules);
        $leadTimePercent = $this->adjustmentPercentFor((float) $leadTimeDays, $leadTimeRules);
        $totalPercent = $occupancyPercent + $leadTimePercent;

        $amount = round($baseAmount * (1 + $totalPercent / 100), 2);

        $floor = round((float) $roomType->Min_daily_price, 2);
        $ceiling = round((float) $roomType->Max_daily_price * 2, 2);

        if ($amount < $floor) {
            $amount = $floor;
        } elseif ($ceiling > 0 && $amount > $ceiling) {
            $amount = $ceiling;
        }

        return ['amount' => $amount, 'source' => 'dynamic'];
    }

    /**
     * @return array{occupancy: Collection<int, DynamicPricingRule>, lead_time: Collection<int, DynamicPricingRule>}
     */
    public function rulesForRoomType(RoomType $roomType): array
    {
        $rules = $roomType->dynamicPricingRules()->orderBy('range_from')->get();

        return [
            'occupancy' => $rules->where('metric', DynamicPricingRule::METRIC_OCCUPANCY)->values(),
            'lead_time' => $rules->where('metric', DynamicPricingRule::METRIC_LEAD_TIME)->values(),
        ];
    }
}
