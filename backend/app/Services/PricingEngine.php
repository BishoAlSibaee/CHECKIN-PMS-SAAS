<?php

namespace App\Services;

use App\Models\PeakDay;
use App\Models\PeakMonth;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\RoomtypePricingplan;
use Carbon\Carbon;

class PricingEngine
{
    public function __construct(private DynamicPricingService $dynamicPricingService) {}

    /**
     * Build per-night breakdown for accrual accounting.
     *
     * @return array<int, array{date: string, base_amount: float, is_peak_day: bool, is_in_plan: bool, price_source: string}>
     */
    /**
     * @param  array<int, array{date: string, base_amount: float, is_peak_day: bool, is_in_plan: bool, price_source: string}>  $lines
     */
    public function sumBaseAmount(array $lines): float
    {
        return round(array_sum(array_column($lines, 'base_amount')), 2);
    }

    /**
     * @param  array<int, array{date: string, base_amount: float, ...}>  $lines
     * @return array<string, float>
     */
    public function linesToDaysMap(array $lines): array
    {
        $days = [];
        foreach ($lines as $line) {
            $days[$line['date']] = $line['base_amount'];
        }

        return $days;
    }

    /**
     * Group consecutive nights into summary rows (monthly chunk vs daily).
     *
     * @param  array<int, array{date: string, base_amount: float, price_source?: string}>  $lines
     * @return array<int, array{from: string, to: string, kind: string, billing_source?: string, amount: float, nights: int}>
     */
    public function buildPriceSegments(array $lines, int $rentType = 1): array
    {
        if (empty($lines)) {
            return [];
        }

        $segments = [];
        $current = null;

        foreach ($lines as $line) {
            $date = $line['date'];
            $source = (string) ($line['price_source'] ?? '');
            $kind = $this->segmentKindFromSource($source, $rentType);
            $amount = (float) $line['base_amount'];

            if ($current !== null
                && $current['kind'] === $kind
                && ($current['billing_source'] ?? '') === $source
                && $this->isNextCalendarDay($current['to'], $date)
                && abs($amount - round($current['amount'] / max(1, $current['nights']), 2)) < 0.01) {
                $current['to'] = $date;
                $current['amount'] = round($current['amount'] + $amount, 2);
                $current['nights']++;

                continue;
            }

            if ($current !== null) {
                $segments[] = $current;
            }

            $current = [
                'from' => $date,
                'to' => $date,
                'kind' => $kind,
                'billing_source' => $source,
                'amount' => round($amount, 2),
                'nights' => 1,
            ];
        }

        if ($current !== null) {
            $segments[] = $current;
        }

        return $segments;
    }

    private function isNextCalendarDay(string $prevDate, string $nextDate): bool
    {
        return Carbon::parse($prevDate)->addDay()->toDateString() === $nextDate;
    }

    public function calculateStayBase(
        Room $room,
        string $start,
        string $end,
        int $rentType,
        int $priceMode = 0
    ): float {
        return $this->sumBaseAmount(
            $this->buildDailyBreakdown($room, $start, $end, $rentType, $priceMode)
        );
    }

    public function buildDailyBreakdown(
        Room $room,
        string $start,
        string $end,
        int $rentType,
        int $priceMode = 0
    ): array {
        $startDate = Carbon::parse($start)->startOfDay();
        $endDate = Carbon::parse($end)->startOfDay();

        if ($rentType === 0) {
            return $this->buildDailyRentalBreakdown($room, $startDate, $endDate, $priceMode);
        }

        return $this->buildMonthlyAsDailyBreakdown($room, $startDate, $endDate, $priceMode);
    }

    private function buildDailyRentalBreakdown(Room $room, Carbon $startDate, Carbon $endDate, int $priceMode): array
    {
        $roomType = RoomType::findOrFail($room->room_type_id);

        if ($roomType->dynamic_pricing_enabled) {
            return $this->buildDynamicPricingBreakdown($room, $roomType, $startDate, $endDate);
        }

        $start = $startDate->toDateString();
        $end = $endDate->toDateString();

        $stayPlan = $this->resolveStayPricingPlan($room->room_type_id, $start, $end);

        $lines = [];

        for ($date = $startDate->copy(); $date->lt($endDate); $date->addDay()) {
            $nightPlan = $priceMode === 2 ? null : $stayPlan;

            $lines[] = $this->priceSingleNight(
                $date,
                $roomType,
                $nightPlan,
                $start,
                $end,
                $priceMode
            );
        }

        return $lines;
    }

    /**
     * Dynamic pricing for a room type: ignores pricing plans entirely (by
     * design — see the plan doc), anchors each night to the room type's own
     * peak/non-peak Min/Max rate via lineFromRoomType(), then layers on the
     * occupancy% + lead-time% adjustment from DynamicPricingService.
     */
    private function buildDynamicPricingBreakdown(Room $room, RoomType $roomType, Carbon $startDate, Carbon $endDate): array
    {
        $occupancyRates = $this->dynamicPricingService->occupancyRatesForRange($room->room_type_id, $startDate, $endDate);
        $rules = $this->dynamicPricingService->rulesForRoomType($roomType);
        $today = Carbon::today();

        $lines = [];

        for ($date = $startDate->copy(); $date->lt($endDate); $date->addDay()) {
            $isPeakDay = $this->isPeakDay($date->format('l'));
            $dateStr = $date->toDateString();

            $baseLine = $this->lineFromRoomType($date, $roomType, $isPeakDay, false);
            $occupancyRate = $occupancyRates[$dateStr] ?? 0.0;
            $leadTimeDays = (int) $today->diffInDays($date);

            $dynamic = $this->dynamicPricingService->applyDynamicPricing(
                $baseLine['base_amount'],
                $roomType,
                $occupancyRate,
                $leadTimeDays,
                $rules['occupancy'],
                $rules['lead_time']
            );

            $lines[] = $this->line($date, $dynamic['amount'], $isPeakDay, false, $dynamic['source']);
        }

        return $lines;
    }

    /**
     * Among pricing plans overlapping the stay, pick the narrowest date window.
     * This avoids a long-running base plan swallowing a shorter promotional plan
     * (e.g. stay Aug 1–11 with base 2024–2026 and promo Aug 5–31 → use promo from Aug 5).
     */
    public function resolveStayPricingPlan(int $roomTypeId, string $start, string $end): ?RoomtypePricingplan
    {
        $plans = RoomtypePricingplan::with(['pricingplan', 'roomType'])
            ->where('roomtype_id', $roomTypeId)
            ->whereHas('pricingplan', function ($query) use ($start, $end) {
                $query->where('StartDate', '<=', $end)
                    ->where('EndDate', '>=', $start);
            })
            ->get();

        if ($plans->isEmpty()) {
            return null;
        }

        if ($plans->count() === 1) {
            return $plans->first();
        }

        return $plans->sortBy(function (RoomtypePricingplan $link) {
            $planStart = Carbon::parse($link->pricingplan->StartDate)->startOfDay();
            $planEnd = Carbon::parse($link->pricingplan->EndDate)->startOfDay();
            $span = $planStart->diffInDays($planEnd);

            // Prefer narrower window; on tie prefer the plan that starts latest (more specific promo).
            return [$span, $planStart->timestamp * -1];
        })->first();
    }

    public function resolveNightPricingPlan(int $roomTypeId, Carbon $date): ?RoomtypePricingplan
    {
        $dateStr = $date->toDateString();

        return $this->resolveStayPricingPlan($roomTypeId, $dateStr, $dateStr);
    }

    private function priceSingleNight(
        Carbon $date,
        RoomType $roomType,
        ?RoomtypePricingplan $roomTypePlan,
        string $stayStart,
        string $stayEnd,
        int $priceMode
    ): array {
        $dayName = $date->format('l');
        $isPeakDay = $this->isPeakDay($dayName);

        if ($priceMode === 1 && $roomTypePlan) {
            return $this->line($date, (float) $roomTypePlan->DailyPrice, $isPeakDay, true, 'plan');
        }

        if ($priceMode === 2 || ! $roomTypePlan) {
            return $this->lineFromRoomType($date, $roomType, $isPeakDay, false);
        }

        // priceMode 0 — mixed, and a plan overlaps some part of the stay.
        return $this->priceSingleNightMixed(
            $date,
            $roomType,
            $roomTypePlan,
            $isPeakDay
        );
    }

    /**
     * Real "mixed" pricing for a stay that partially overlaps a plan.
     * How a night outside the plan's own dates is priced depends on the
     * room type's active_type:
     * - 0: the plan is disabled for this room type — ignore it for the
     *      whole stay and price every night as if there were no plan.
     * - 1: genuinely mixed — a night inside the plan's dates uses the
     *      plan's price, a night outside uses the room type's normal
     *      (peak/non-peak) rate.
     * - 2: the plan's price extends to the whole stay, even nights that
     *      fall outside its own configured date range.
     */
    private function priceSingleNightMixed(
        Carbon $date,
        RoomType $roomType,
        RoomtypePricingplan $roomTypePlan,
        bool $isPeakDay
    ): array {
        $activeType = (int) $roomType->active_type;

        if ($activeType === 0) {
            return $this->lineFromRoomType($date, $roomType, $isPeakDay, false);
        }

        if ($activeType === 2) {
            return $this->line($date, (float) $roomTypePlan->DailyPrice, $isPeakDay, true, 'plan');
        }

        if ($this->nightIsWithinPlanDates($date, $roomTypePlan)) {
            return $this->line($date, (float) $roomTypePlan->DailyPrice, $isPeakDay, true, 'plan');
        }

        return $this->lineFromRoomType($date, $roomType, $isPeakDay, false);
    }

    private function nightIsWithinPlanDates(Carbon $date, RoomtypePricingplan $roomTypePlan): bool
    {
        $dateStr = $date->toDateString();
        $planStartStr = Carbon::parse($roomTypePlan->pricingplan->StartDate)->toDateString();
        $planEndStr = Carbon::parse($roomTypePlan->pricingplan->EndDate)->toDateString();

        return $dateStr >= $planStartStr && $dateStr <= $planEndStr;
    }

    private function lineFromRoomType(Carbon $date, RoomType $roomType, bool $isPeakDay, bool $inPlan): array
    {
        $activeType = (int) $roomType->active_type;

        if ($activeType === 0) {
            return $this->line($date, (float) $roomType->Min_daily_price, $isPeakDay, $inPlan, 'min');
        }

        if ($activeType === 2) {
            return $this->line($date, (float) $roomType->Max_daily_price, $isPeakDay, $inPlan, 'max');
        }

        $amount = $isPeakDay ? (float) $roomType->Max_daily_price : (float) $roomType->Min_daily_price;

        return $this->line($date, $amount, $isPeakDay, $inPlan, $isPeakDay ? 'max' : 'min');
    }

    private function line(Carbon $date, float $amount, bool $isPeakDay, bool $inPlan, string $source): array
    {
        return [
            'date' => $date->toDateString(),
            'base_amount' => round($amount, 2),
            'is_peak_day' => $isPeakDay,
            'is_in_plan' => $inPlan,
            'price_source' => $source,
        ];
    }

    /**
     * Monthly rent: full calendar months at monthly rate, extra days at daily rate.
     */
    private function buildMonthlyAsDailyBreakdown(Room $room, Carbon $startDate, Carbon $endDate, int $priceMode): array
    {
        $roomType = RoomType::findOrFail($room->room_type_id);
        $start = $startDate->toDateString();
        $end = $endDate->toDateString();

        $stayPlan = $this->resolveStayPricingPlan($room->room_type_id, $start, $end);

        $lines = [];
        $tempDate = $startDate->copy();
        $targetDay = $startDate->day;
        $completedFullMonth = false;

        while ($tempDate->lt($endDate)) {
            $naturalPeriodEnd = $this->naturalMonthlyPeriodEnd($tempDate, $targetDay);

            if ($naturalPeriodEnd->lte($endDate)) {
                $chunkDays = $tempDate->diffInDays($naturalPeriodEnd);
                if ($chunkDays < 1) {
                    break;
                }

                array_push(
                    $lines,
                    ...$this->buildMonthlyChunkLines(
                        $tempDate,
                        $chunkDays,
                        $naturalPeriodEnd,
                        $roomType,
                        $stayPlan,
                        $priceMode,
                        $startDate,
                        true
                    )
                );

                $tempDate = $naturalPeriodEnd;
                $completedFullMonth = true;

                continue;
            }

            if (! $completedFullMonth) {
                $chunkDays = $tempDate->diffInDays($endDate);
                if ($chunkDays < 1) {
                    break;
                }

                $fullPeriodDays = max(1, $tempDate->diffInDays($naturalPeriodEnd));

                array_push(
                    $lines,
                    ...$this->buildMonthlyChunkLines(
                        $tempDate,
                        $chunkDays,
                        $naturalPeriodEnd,
                        $roomType,
                        $stayPlan,
                        $priceMode,
                        $startDate,
                        false,
                        $fullPeriodDays
                    )
                );

                break;
            }

            while ($tempDate->lt($endDate)) {
                $nightPlan = $priceMode === 2 ? null : $stayPlan;
                $lines[] = $this->priceSingleNight(
                    $tempDate,
                    $roomType,
                    $nightPlan,
                    $start,
                    $end,
                    $priceMode
                );
                $tempDate->addDay();
            }

            break;
        }

        return $lines;
    }

    /**
     * @return array<int, array{date: string, base_amount: float, is_peak_day: bool, is_in_plan: bool, price_source: string}>
     */
    private function buildMonthlyChunkLines(
        Carbon $periodStart,
        int $chunkDays,
        Carbon $naturalPeriodEnd,
        RoomType $roomType,
        ?RoomtypePricingplan $stayPlan,
        int $priceMode,
        Carbon $stayStart,
        bool $isFullPeriod,
        int $fullPeriodDays = 0
    ): array {
        if ($priceMode === 0) {
            return $this->buildMixedMonthChunkLines(
                $periodStart,
                $chunkDays,
                $roomType,
                $stayPlan,
                $stayStart,
                $naturalPeriodEnd,
                $isFullPeriod,
                $fullPeriodDays
            );
        }

        if ($priceMode === 1 && $stayPlan && $this->chunkFullyWithinPlan($periodStart, $naturalPeriodEnd, $stayPlan)) {
            return $this->buildFullPlanChunkLines($periodStart, $chunkDays, $stayPlan, $isFullPeriod, $fullPeriodDays);
        }

        if ($priceMode === 1 && $stayPlan) {
            return $this->buildMixedMonthChunkLines(
                $periodStart,
                $chunkDays,
                $roomType,
                $stayPlan,
                $stayStart,
                $naturalPeriodEnd,
                $isFullPeriod,
                $fullPeriodDays
            );
        }

        return $this->buildRoomTypeMonthlyChunkByCalendarMonth($periodStart, $chunkDays, $roomType);
    }

    private function chunkFullyWithinPlan(
        Carbon $periodStart,
        Carbon $periodEnd,
        RoomtypePricingplan $roomTypePlan
    ): bool {
        $planStart = Carbon::parse($roomTypePlan->pricingplan->StartDate)->startOfDay();
        $planEnd = Carbon::parse($roomTypePlan->pricingplan->EndDate)->startOfDay();

        return $periodStart->gte($planStart) && $periodEnd->lte($planEnd);
    }

    /**
     * The plan's monthly price applied to every night of the chunk — used
     * both when the chunk falls fully within the plan's own dates, and
     * (active_type = 2) when the plan's price is extended past its own
     * dates to cover the rest of a partially-overlapping stay.
     *
     * @return array<int, array{date: string, base_amount: float, is_peak_day: bool, is_in_plan: bool, price_source: string}>
     */
    private function buildFullPlanChunkLines(
        Carbon $periodStart,
        int $chunkDays,
        RoomtypePricingplan $roomTypePlan,
        bool $isFullPeriod,
        int $fullPeriodDays
    ): array {
        $chunkTotal = (float) $roomTypePlan->MonthlyPrice;
        if (! $isFullPeriod) {
            $denominator = max(1, $fullPeriodDays);
            $chunkTotal = round($chunkTotal * ($chunkDays / $denominator), 2);
        }
        $amounts = $this->distributeAmount($chunkTotal, $chunkDays);

        $lines = [];
        for ($i = 0; $i < $chunkDays; $i++) {
            $date = $periodStart->copy()->addDays($i);
            $lines[] = $this->line(
                $date,
                $amounts[$i] ?? 0.0,
                $this->isPeakMonth($date->format('F')),
                true,
                'plan'
            );
        }

        return $lines;
    }

    private function naturalMonthlyPeriodEnd(Carbon $periodStart, int $targetDay): Carbon
    {
        $nextMonth = $periodStart->copy()->addMonthNoOverflow();

        if ($periodStart->isLastOfMonth()) {
            return $nextMonth->copy()->endOfMonth()->startOfDay();
        }

        return $nextMonth->copy()->day(min($targetDay, $nextMonth->daysInMonth));
    }

    private function buildMixedMonthChunkLines(
        Carbon $periodStart,
        int $chunkDays,
        RoomType $roomType,
        ?RoomtypePricingplan $roomTypePlan,
        Carbon $stayStart,
        Carbon $naturalPeriodEnd,
        bool $isFullPeriod,
        int $fullPeriodDays = 0
    ): array {
        if ($roomTypePlan !== null) {
            $activeType = (int) $roomType->active_type;

            if ($activeType === 0) {
                // The plan is disabled for this room type — price the whole
                // chunk as if no plan existed at all.
                return $this->buildRoomTypeMonthlyChunkByCalendarMonth($periodStart, $chunkDays, $roomType);
            }

            if ($activeType === 2) {
                // Plan's price extends to the whole chunk, even nights that
                // fall outside its own configured date range.
                return $this->buildFullPlanChunkLines($periodStart, $chunkDays, $roomTypePlan, $isFullPeriod, $fullPeriodDays);
            }
        }

        /** @var array<string, array{plan: int, room: int, sample: Carbon}> $byMonth */
        $byMonth = [];
        /** @var array<int, array{date: Carbon, ym: string, in_plan: bool, night_plan: ?RoomtypePricingplan}> $nightMeta */
        $nightMeta = [];
        $hasAnyPlanNight = false;

        for ($i = 0; $i < $chunkDays; $i++) {
            $date = $periodStart->copy()->addDays($i);
            $ym = $date->format('Y-m');
            if (! isset($byMonth[$ym])) {
                $byMonth[$ym] = ['plan' => 0, 'room' => 0, 'sample' => $date->copy()];
            }

            $nightPlan = $this->resolveNightPricingPlan($roomType->id, $date);
            $inPlan = $nightPlan !== null;
            $hasAnyPlanNight = $hasAnyPlanNight || $inPlan;
            if ($inPlan) {
                $byMonth[$ym]['plan']++;
            } else {
                $byMonth[$ym]['room']++;
            }

            $nightMeta[] = [
                'date' => $date->copy(),
                'ym' => $ym,
                'in_plan' => $inPlan,
                'night_plan' => $nightPlan,
            ];
        }

        if (! $hasAnyPlanNight) {
            return $this->buildRoomTypeMonthlyChunkByCalendarMonth($periodStart, $chunkDays, $roomType);
        }

        if ($this->chunkMonthsShareSameMonthlyClassification($periodStart, $chunkDays, $roomType)) {
            return $this->buildUniformMixedMonthChunkLines(
                $periodStart,
                $chunkDays,
                $roomType,
                $nightMeta
            );
        }

        /** @var array<string, array{plan_amounts: array<int, float>, room_amounts: array<int, float>}> $alloc */
        $alloc = [];
        foreach ($byMonth as $ym => $counts) {
            $daysInMonth = $counts['sample']->daysInMonth;
            $planN = $counts['plan'];
            $roomN = $counts['room'];
            $roomMonthly = $this->monthlyRateForPeriod($counts['sample'], $roomType);

            $planPart = 0.0;
            foreach ($nightMeta as $meta) {
                if ($meta['ym'] !== $ym || ! $meta['in_plan'] || ! $meta['night_plan']) {
                    continue;
                }
                $planPart += (1 / $daysInMonth) * (float) $meta['night_plan']->MonthlyPrice;
            }
            $roomPart = $roomN > 0 ? ($roomN / $daysInMonth) * $roomMonthly : 0.0;

            $alloc[$ym] = [
                'plan_amounts' => $this->distributeAmount(round($planPart, 2), $planN),
                'room_amounts' => $this->distributeAmount(round($roomPart, 2), $roomN),
            ];
        }

        $planIdx = [];
        $roomIdx = [];
        foreach (array_keys($byMonth) as $ym) {
            $planIdx[$ym] = 0;
            $roomIdx[$ym] = 0;
        }

        $lines = [];
        foreach ($nightMeta as $meta) {
            $date = $meta['date'];
            $ym = $meta['ym'];
            $isPeakMonth = $this->isPeakMonth($date->format('F'));

            if ($meta['in_plan']) {
                $amount = $alloc[$ym]['plan_amounts'][$planIdx[$ym]++] ?? 0.0;
                $lines[] = $this->line($date, $amount, $isPeakMonth, true, 'plan');
            } else {
                $amount = $alloc[$ym]['room_amounts'][$roomIdx[$ym]++] ?? 0.0;
                $source = $this->roomTypeMonthlySource($roomType, $date);
                $lines[] = $this->line($date, $amount, $isPeakMonth, false, $source);
            }
        }

        return $lines;
    }

    /**
     * Prorate monthly rent per calendar month (peak vs non-peak month rates).
     *
     * @return array<int, array{date: string, base_amount: float, is_peak_day: bool, is_in_plan: bool, price_source: string}>
     */
    private function buildRoomTypeMonthlyChunkByCalendarMonth(
        Carbon $periodStart,
        int $chunkDays,
        RoomType $roomType
    ): array {
        if ($this->chunkMonthsShareSameMonthlyClassification($periodStart, $chunkDays, $roomType)) {
            return $this->buildUniformMonthlyChunkLines($periodStart, $chunkDays, $roomType);
        }

        /** @var array<string, array{count: int, sample: Carbon}> $byMonth */
        $byMonth = [];
        $nightMeta = [];

        for ($i = 0; $i < $chunkDays; $i++) {
            $date = $periodStart->copy()->addDays($i);
            $ym = $date->format('Y-m');
            if (! isset($byMonth[$ym])) {
                $byMonth[$ym] = ['count' => 0, 'sample' => $date->copy()];
            }
            $byMonth[$ym]['count']++;
            $nightMeta[] = ['date' => $date->copy(), 'ym' => $ym];
        }

        /** @var array<string, array<int, float>> $alloc */
        $alloc = [];
        foreach ($byMonth as $ym => $counts) {
            $n = $counts['count'];
            $daysInMonth = $counts['sample']->daysInMonth;
            $monthly = $this->monthlyRateForPeriod($counts['sample'], $roomType);
            $part = $n > 0 ? ($n / $daysInMonth) * $monthly : 0.0;
            $alloc[$ym] = $this->distributeAmount(round($part, 2), $n);
        }

        $idx = [];
        foreach (array_keys($byMonth) as $ym) {
            $idx[$ym] = 0;
        }

        $lines = [];
        foreach ($nightMeta as $meta) {
            $date = $meta['date'];
            $ym = $meta['ym'];
            $sample = $byMonth[$ym]['sample'];
            $amount = $alloc[$ym][$idx[$ym]++] ?? 0.0;
            $lines[] = $this->line(
                $date,
                $amount,
                $this->isPeakMonth($date->format('F')),
                false,
                $this->roomTypeMonthlySource($roomType, $sample)
            );
        }

        return $lines;
    }

    /**
     * One monthly rent for the whole natural period when every calendar month
     * in the chunk shares the same peak/normal classification.
     *
     * @return array<int, array{date: string, base_amount: float, is_peak_day: bool, is_in_plan: bool, price_source: string}>
     */
    private function buildUniformMonthlyChunkLines(
        Carbon $periodStart,
        int $chunkDays,
        RoomType $roomType
    ): array {
        $monthly = $this->monthlyRateForPeriod($periodStart, $roomType);
        $amounts = $this->distributeAmount($monthly, $chunkDays);
        $source = $this->roomTypeMonthlySource($roomType, $periodStart);
        $lines = [];

        for ($i = 0; $i < $chunkDays; $i++) {
            $date = $periodStart->copy()->addDays($i);
            $lines[] = $this->line(
                $date,
                $amounts[$i] ?? 0.0,
                $this->isPeakMonth($date->format('F')),
                false,
                $source
            );
        }

        return $lines;
    }

    /**
     * @param  array<int, array{date: Carbon, ym: string, in_plan: bool, night_plan: ?RoomtypePricingplan}>  $nightMeta
     * @return array<int, array{date: string, base_amount: float, is_peak_day: bool, is_in_plan: bool, price_source: string}>
     */
    private function buildUniformMixedMonthChunkLines(
        Carbon $periodStart,
        int $chunkDays,
        RoomType $roomType,
        array $nightMeta
    ): array {
        $amounts = array_fill(0, $chunkDays, 0.0);

        // "Uniform" here only means every month in the chunk shares the same
        // peak/non-peak classification — the chunk can still span a calendar
        // month boundary (e.g. two peak months with different day counts),
        // so each group must still be prorated by ITS OWN month's day count,
        // not the chunk's total night count.
        /** @var array<string, list<int>> $planIndexesByGroup */
        $planIndexesByGroup = [];
        /** @var array<string, RoomtypePricingplan> $planByGroup */
        $planByGroup = [];
        /** @var array<string, list<int>> $roomIndexesByMonth */
        $roomIndexesByMonth = [];

        foreach ($nightMeta as $i => $meta) {
            if ($meta['in_plan'] && $meta['night_plan']) {
                $groupKey = $meta['night_plan']->id.':'.$meta['ym'];
                $planIndexesByGroup[$groupKey][] = $i;
                $planByGroup[$groupKey] = $meta['night_plan'];
            } else {
                $roomIndexesByMonth[$meta['ym']][] = $i;
            }
        }

        foreach ($planIndexesByGroup as $groupKey => $indexes) {
            $count = count($indexes);
            $daysInMonth = $nightMeta[$indexes[0]]['date']->daysInMonth;
            $planMonthly = (float) $planByGroup[$groupKey]->MonthlyPrice;
            $planTotal = round($planMonthly * ($count / $daysInMonth), 2);
            $planAmounts = $this->distributeAmount($planTotal, $count);
            foreach ($indexes as $j => $idx) {
                $amounts[$idx] = $planAmounts[$j] ?? 0.0;
            }
        }

        foreach ($roomIndexesByMonth as $indexes) {
            $count = count($indexes);
            $sampleDate = $nightMeta[$indexes[0]]['date'];
            $daysInMonth = $sampleDate->daysInMonth;
            $roomMonthly = $this->monthlyRateForPeriod($sampleDate, $roomType);
            $roomTotal = round($roomMonthly * ($count / $daysInMonth), 2);
            $roomAmounts = $this->distributeAmount($roomTotal, $count);
            foreach ($indexes as $j => $idx) {
                $amounts[$idx] = $roomAmounts[$j] ?? 0.0;
            }
        }

        $lines = [];

        for ($i = 0; $i < $chunkDays; $i++) {
            $meta = $nightMeta[$i];
            $date = $meta['date'];
            $isPeakMonth = $this->isPeakMonth($date->format('F'));

            if ($meta['in_plan']) {
                $lines[] = $this->line($date, $amounts[$i] ?? 0.0, $isPeakMonth, true, 'plan');
            } else {
                $lines[] = $this->line(
                    $date,
                    $amounts[$i] ?? 0.0,
                    $isPeakMonth,
                    false,
                    $this->roomTypeMonthlySource($roomType, $date)
                );
            }
        }

        return $lines;
    }

    private function chunkMonthsShareSameMonthlyClassification(
        Carbon $periodStart,
        int $chunkDays,
        RoomType $roomType
    ): bool {
        $activeType = (int) $roomType->active_type;
        if ($activeType === 0 || $activeType === 2) {
            return true;
        }

        $firstPeak = null;
        for ($i = 0; $i < $chunkDays; $i++) {
            $date = $periodStart->copy()->addDays($i);
            $isPeak = $this->isPeakMonth($date->format('F'));
            if ($firstPeak === null) {
                $firstPeak = $isPeak;
            } elseif ($firstPeak !== $isPeak) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<int, float>
     */
    private function distributeAmount(float $total, int $nights): array
    {
        if ($nights <= 0) {
            return [];
        }

        if ($nights === 1) {
            return [round($total, 2)];
        }

        $totalCents = (int) round($total * 100);
        $baseCents = intdiv($totalCents, $nights);
        $remainder = $totalCents - ($baseCents * $nights);
        $amounts = array_fill(0, $nights, $baseCents);

        for ($i = 0; $i < $remainder; $i++) {
            $amounts[$i]++;
        }

        return array_map(static fn (int $cents): float => round($cents / 100, 2), $amounts);
    }

    private function roomTypeMonthlySource(RoomType $roomType, Carbon $date): string
    {
        $activeType = (int) $roomType->active_type;
        if ($activeType === 0) {
            return 'min';
        }
        if ($activeType === 2) {
            return 'max';
        }

        return $this->isPeakMonth($date->format('F')) ? 'max' : 'min';
    }

    private function segmentKindFromSource(string $source, int $rentType): string
    {
        // Daily rent: every night is billed daily; price_source (min/max/plan) is not "monthly billing".
        if ($rentType === 0) {
            return 'daily';
        }

        if (in_array($source, ['monthly', 'plan', 'min', 'max'], true)) {
            return 'monthly';
        }

        return 'daily';
    }

    private function monthlyRateForPeriod(Carbon $periodStart, RoomType $roomType): float
    {
        $isPeakMonth = $this->isPeakMonth($periodStart->format('F'));
        $activeType = (int) $roomType->active_type;

        if ($activeType === 0) {
            return (float) $roomType->Min_monthly_price;
        }

        if ($activeType === 2) {
            return (float) $roomType->Max_monthly_price;
        }

        return $isPeakMonth
            ? (float) $roomType->Max_monthly_price
            : (float) $roomType->Min_monthly_price;
    }

    public function isPeakDay(string $dayNameEn): bool
    {
        return PeakDay::where('day_name_en', $dayNameEn)->value('check') == 1;
    }

    public function isPeakMonth(string $monthNameEn): bool
    {
        return PeakMonth::where('month_name_en', $monthNameEn)->value('check') == 1;
    }
}
