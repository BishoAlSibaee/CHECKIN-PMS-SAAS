<?php

namespace App\Services\Channex;

use App\Models\ChannexProperty;
use App\Models\ChannexRoomTypeMapping;
use App\Models\OtaRatePlan;
use App\Models\Room;
use App\Services\PricingEngine;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Pushes one room type's availability (respecting the manual OTA allocation
 * ceiling — see OtaAllocationService) and each of its active OTA rate plans'
 * daily rates to Channex. Consecutive equal-value dates are compressed into
 * one range instead of one entry per day, per Channex's own guidance.
 */
class ChannexAriPushService
{
    public function __construct(
        private ChannexClient $client,
        private OtaAllocationService $allocationService,
        private PricingEngine $pricingEngine,
    ) {}

    public function pushForRoomType(int $hotelId, int $roomTypeId, ?Carbon $from = null, ?Carbon $to = null): void
    {
        if (! $this->client->isEnabled()) {
            return;
        }

        $property = ChannexProperty::where('hotel_id', $hotelId)->first();
        $roomTypeMapping = ChannexRoomTypeMapping::where('hotel_id', $hotelId)->where('room_type_id', $roomTypeId)->first();
        if ($property === null || $roomTypeMapping === null) {
            // Not connected to Channex for this room type yet — nothing to push.
            return;
        }

        $from = ($from ?? Carbon::today())->copy()->startOfDay();
        $to = ($to ?? $from->copy()->addDays((int) config('channex.ari_push_horizon_days', 180)))->copy()->startOfDay();

        try {
            $this->pushAvailability($property->channex_property_id, $roomTypeMapping->channex_room_type_id, $hotelId, $roomTypeId, $from, $to);
            $this->pushRates($property->channex_property_id, $hotelId, $roomTypeId, $from, $to);
        } catch (\Throwable $e) {
            // Never let a Channex outage break the booking flow that triggered
            // this push — same resilience posture as the GRMS jobs.
            Log::warning('Channex ARI push failed', [
                'hotel_id' => $hotelId,
                'room_type_id' => $roomTypeId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function pushAvailability(string $channexPropertyId, string $channexRoomTypeId, int $hotelId, int $roomTypeId, Carbon $from, Carbon $to): void
    {
        $availability = $this->allocationService->availabilityForDateRange($hotelId, $roomTypeId, $from, $to->copy()->addDay());
        if ($availability === []) {
            return;
        }

        $values = [];
        foreach ($this->compressRanges($availability) as $range) {
            $values[] = [
                'property_id' => $channexPropertyId,
                'room_type_id' => $channexRoomTypeId,
                'date_from' => $range['from'],
                'date_to' => $range['to'],
                'availability' => $range['value'],
            ];
        }

        if ($values !== []) {
            $this->client->pushAvailability($values);
        }
    }

    private function pushRates(string $channexPropertyId, int $hotelId, int $roomTypeId, Carbon $from, Carbon $to): void
    {
        $ratePlans = OtaRatePlan::where('hotel_id', $hotelId)
            ->where('room_type_id', $roomTypeId)
            ->where('is_active', true)
            ->with('channexMapping')
            ->get();
        if ($ratePlans->isEmpty()) {
            return;
        }

        $representativeRoom = Room::query()
            ->join('buildings', 'buildings.id', '=', 'rooms.building_id')
            ->where('buildings.hotel_id', $hotelId)
            ->where('rooms.room_type_id', $roomTypeId)
            ->where('rooms.active', 1)
            ->select('rooms.*')
            ->first();
        if ($representativeRoom === null) {
            return;
        }

        $dailyBreakdown = $this->pricingEngine->buildDailyBreakdown(
            $representativeRoom,
            $from->toDateString(),
            $to->toDateString(),
            0, // nightly rental
            0
        );
        $baseRatesByDate = $this->pricingEngine->linesToDaysMap($dailyBreakdown);

        foreach ($ratePlans as $ratePlan) {
            $mapping = $ratePlan->channexMapping;
            if ($mapping === null) {
                continue;
            }

            $adjustedRates = [];
            foreach ($baseRatesByDate as $date => $baseRate) {
                $adjusted = round((float) $baseRate * (1 + $ratePlan->price_adjustment_percent / 100), 2);
                // Minor units (halalas) — integer on write, per Channex's contract.
                $adjustedRates[$date] = (int) round(max(0, $adjusted) * 100);
            }

            $values = [];
            foreach ($this->compressRanges($adjustedRates) as $range) {
                $values[] = [
                    'property_id' => $channexPropertyId,
                    'rate_plan_id' => $mapping->channex_rate_plan_id,
                    'date_from' => $range['from'],
                    'date_to' => $range['to'],
                    'rate' => $range['value'],
                ];
            }

            if ($values !== []) {
                $this->client->pushRestrictions($values);
            }
        }
    }

    /**
     * @param  array<string, int>  $valuesByDate  sorted-by-date Y-m-d => value
     * @return list<array{from: string, to: string, value: int}>
     */
    private function compressRanges(array $valuesByDate): array
    {
        $ranges = [];
        $rangeStart = null;
        $rangeEnd = null;
        $rangeValue = null;

        foreach ($valuesByDate as $date => $value) {
            if ($rangeValue === null) {
                $rangeStart = $rangeEnd = $date;
                $rangeValue = $value;

                continue;
            }

            $isConsecutiveDay = Carbon::parse($rangeEnd)->addDay()->toDateString() === $date;
            if ($isConsecutiveDay && $value === $rangeValue) {
                $rangeEnd = $date;

                continue;
            }

            $ranges[] = ['from' => $rangeStart, 'to' => $rangeEnd, 'value' => $rangeValue];
            $rangeStart = $rangeEnd = $date;
            $rangeValue = $value;
        }

        if ($rangeValue !== null) {
            $ranges[] = ['from' => $rangeStart, 'to' => $rangeEnd, 'value' => $rangeValue];
        }

        return $ranges;
    }
}
