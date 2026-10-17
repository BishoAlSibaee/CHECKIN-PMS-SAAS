<?php

namespace App\Services;

use App\Models\RoomtypePricingplan;

class PricingPlanOverlapService
{
    /**
     * Find an existing room-type pricing link whose plan dates overlap [startDate, endDate].
     */
    public function findConflict(
        int $roomtypeId,
        string $startDate,
        string $endDate,
        ?int $excludeRoomtypePricingId = null
    ): ?RoomtypePricingplan {
        $query = RoomtypePricingplan::query()
            ->with('pricingplan')
            ->where('roomtype_id', $roomtypeId)
            ->whereHas('pricingplan', function ($q) use ($startDate, $endDate) {
                $q->whereDate('StartDate', '<=', $endDate)
                    ->whereDate('EndDate', '>=', $startDate);
            });

        if ($excludeRoomtypePricingId !== null) {
            $query->where('id', '!=', $excludeRoomtypePricingId);
        }

        return $query->first();
    }

    public function hasConflict(
        int $roomtypeId,
        string $startDate,
        string $endDate,
        ?int $excludeRoomtypePricingId = null
    ): bool {
        return $this->findConflict($roomtypeId, $startDate, $endDate, $excludeRoomtypePricingId) !== null;
    }

    public function conflictMessage(RoomtypePricingplan $conflict): string
    {
        $plan = $conflict->pricingplan;
        $name = $plan?->NameEn ?: $plan?->NameAr ?: ('Plan #' . $conflict->pricingplan_id);

        return sprintf(
            'This room type already has a pricing plan ("%s") from %s to %s that overlaps the selected dates.',
            $name,
            $plan?->StartDate ?? '?',
            $plan?->EndDate ?? '?'
        );
    }
}
