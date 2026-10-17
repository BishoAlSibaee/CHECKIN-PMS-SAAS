<?php

namespace App\Services;

use App\Models\Pricingplan;
use App\Models\RoomType;
use App\Models\RoomtypePricingplan;
use Illuminate\Support\Facades\DB;

class TestRoomTypeCleanupService
{
    /** English name prefixes left behind by automated tests/scripts. */
    public const TEST_NAME_PREFIXES = [
        'Mixed API Test ',
        'No Overlap Plan ',
        'Pricing Overlap Test ',
        'Base Long ',
        'August Promo ',
        'Late Plan ',
    ];

    public function isTestRoomTypeName(string $nameEn): bool
    {
        foreach (self::TEST_NAME_PREFIXES as $prefix) {
            if (str_starts_with($nameEn, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{deleted_types: int, deleted_links: int, deleted_plans: int, skipped_in_use: int}
     */
    public function purgeOrphanTestRoomTypes(): array
    {
        $stats = [
            'deleted_types' => 0,
            'deleted_links' => 0,
            'deleted_plans' => 0,
            'skipped_in_use' => 0,
        ];

        $candidates = RoomType::query()
            ->where(function ($query) {
                foreach (self::TEST_NAME_PREFIXES as $prefix) {
                    $query->orWhere('name_en', 'like', $prefix . '%');
                }
            })
            ->orderBy('id')
            ->get();

        foreach ($candidates as $roomType) {
            if ($roomType->rooms()->exists()) {
                $stats['skipped_in_use']++;
                continue;
            }

            DB::transaction(function () use ($roomType, &$stats) {
                $purged = $this->purgeRoomTypeTree((int) $roomType->id);
                foreach ($purged as $key => $value) {
                    $stats[$key] += $value;
                }
            });
        }

        return $stats;
    }

    /**
     * @return array{deleted_types: int, deleted_links: int, deleted_plans: int, skipped_in_use: int}
     */
    public function purgeRoomTypeTree(int $roomTypeId): array
    {
        $stats = [
            'deleted_types' => 0,
            'deleted_links' => 0,
            'deleted_plans' => 0,
            'skipped_in_use' => 0,
        ];

        $roomType = RoomType::query()->find($roomTypeId);
        if (!$roomType) {
            return $stats;
        }

        if ($roomType->rooms()->exists()) {
            $stats['skipped_in_use'] = 1;

            return $stats;
        }

        $links = RoomtypePricingplan::query()
            ->where('roomtype_id', $roomTypeId)
            ->get();

        foreach ($links as $link) {
            $planId = (int) $link->pricingplan_id;
            $link->delete();
            $stats['deleted_links']++;

            if ($planId > 0 && !RoomtypePricingplan::query()->where('pricingplan_id', $planId)->exists()) {
                Pricingplan::query()->whereKey($planId)->delete();
                $stats['deleted_plans']++;
            }
        }

        $roomType->delete();
        $stats['deleted_types'] = 1;

        return $stats;
    }
}
