<?php

namespace Database\Seeders;

use App\Models\Pricingplan;
use App\Models\RoomType;
use App\Models\RoomtypePricingplan;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Restores demo pricing so mixed mode works for Aug 2026 stays
 * (plan from Aug 5, nights before use room-type rates).
 */
class DemoPricingFixSeeder extends Seeder
{
    public function run(): void
    {
        $standard = Pricingplan::where('NameEn', 'Standard Rate 2024-2026')->first();
        if ($standard) {
            $standard->update([
                'StartDate' => '2026-08-05',
                'EndDate' => '2026-12-31',
                'ActiveType' => 1,
            ]);
        }

        $demoTypeIds = RoomType::query()
            ->whereIn('name_en', ['Standard', 'Superior', 'Deluxe', 'Family', 'Suite'])
            ->orderBy('id')
            ->pluck('id')
            ->all();
        $daily = [220, 300, 380, 420, 620];
        $monthly = [5200, 6800, 9000, 10200, 15000];

        if ($standard) {
            foreach ($demoTypeIds as $i => $typeId) {
                RoomtypePricingplan::where('roomtype_id', $typeId)
                    ->where('pricingplan_id', $standard->id)
                    ->update([
                        'DailyPrice' => $daily[$i],
                        'MonthlyPrice' => $monthly[$i],
                    ]);
            }
        }

        $keepPlanIds = Pricingplan::whereIn('NameEn', [
            'Standard Rate 2024-2026',
            'Summer Peak 2025',
        ])->pluck('id');

        RoomtypePricingplan::whereIn('roomtype_id', $demoTypeIds)
            ->whereNotIn('pricingplan_id', $keepPlanIds)
            ->delete();

        $orphanPlanIds = DB::table('pricing_plans')
            ->whereNotIn('id', function ($q) {
                $q->select('pricingplan_id')->from('roomtype_pricingplan');
            })
            ->pluck('id');

        if ($orphanPlanIds->isNotEmpty()) {
            Pricingplan::whereIn('id', $orphanPlanIds)->delete();
        }
    }
}
