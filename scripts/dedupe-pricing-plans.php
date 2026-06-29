<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

$deletedLinks = 0;
$deletedPlans = 0;
$relinkedPlans = 0;

if (! Schema::hasTable('roomtype_pricingplan')) {
    echo "roomtype_pricingplan table missing\n";
    exit(1);
}

// 1) Same room type + same plan id linked more than once — keep lowest id.
$linkDupes = DB::table('roomtype_pricingplan')
    ->select('roomtype_id', 'pricingplan_id', DB::raw('MIN(id) as keep_id'), DB::raw('COUNT(*) as c'))
    ->groupBy('roomtype_id', 'pricingplan_id')
    ->having('c', '>', 1)
    ->get();

foreach ($linkDupes as $row) {
    $deletedLinks += DB::table('roomtype_pricingplan')
        ->where('roomtype_id', $row->roomtype_id)
        ->where('pricingplan_id', $row->pricingplan_id)
        ->where('id', '!=', $row->keep_id)
        ->delete();
}

if (! Schema::hasTable('pricing_plans')) {
    echo "deleted_duplicate_links={$deletedLinks}\n";
    echo 'remaining_duplicate_links=' . DB::table('roomtype_pricingplan')
        ->selectRaw('roomtype_id, pricingplan_id, COUNT(*) c')
        ->groupBy('roomtype_id', 'pricingplan_id')
        ->having('c', '>', 1)
        ->count() . "\n";
    exit(0);
}

// 2) Identical plan definitions — merge links to canonical (MIN id) plan, delete extras.
$planGroups = DB::table('pricing_plans')
    ->select('NameAr', 'NameEn', 'StartDate', 'EndDate', 'ActiveType', DB::raw('MIN(id) as keep_id'), DB::raw('COUNT(*) as c'))
    ->groupBy('NameAr', 'NameEn', 'StartDate', 'EndDate', 'ActiveType')
    ->having('c', '>', 1)
    ->get();

foreach ($planGroups as $group) {
    $duplicatePlanIds = DB::table('pricing_plans')
        ->where('NameAr', $group->NameAr)
        ->where('NameEn', $group->NameEn)
        ->where('StartDate', $group->StartDate)
        ->where('EndDate', $group->EndDate)
        ->where('ActiveType', $group->ActiveType)
        ->where('id', '!=', $group->keep_id)
        ->pluck('id');

    foreach ($duplicatePlanIds as $dupPlanId) {
        $links = DB::table('roomtype_pricingplan')->where('pricingplan_id', $dupPlanId)->get();
        foreach ($links as $link) {
            $exists = DB::table('roomtype_pricingplan')
                ->where('roomtype_id', $link->roomtype_id)
                ->where('pricingplan_id', $group->keep_id)
                ->exists();

            if ($exists) {
                DB::table('roomtype_pricingplan')->where('id', $link->id)->delete();
                $deletedLinks++;
            } else {
                DB::table('roomtype_pricingplan')
                    ->where('id', $link->id)
                    ->update(['pricingplan_id' => $group->keep_id]);
                $relinkedPlans++;
            }
        }

        DB::table('pricing_plans')->where('id', $dupPlanId)->delete();
        $deletedPlans++;
    }
}

// 3) Re-run link dedupe after merges.
$linkDupesAfter = DB::table('roomtype_pricingplan')
    ->select('roomtype_id', 'pricingplan_id', DB::raw('MIN(id) as keep_id'), DB::raw('COUNT(*) as c'))
    ->groupBy('roomtype_id', 'pricingplan_id')
    ->having('c', '>', 1)
    ->get();

foreach ($linkDupesAfter as $row) {
    $deletedLinks += DB::table('roomtype_pricingplan')
        ->where('roomtype_id', $row->roomtype_id)
        ->where('pricingplan_id', $row->pricingplan_id)
        ->where('id', '!=', $row->keep_id)
        ->delete();
}

echo "deleted_duplicate_links={$deletedLinks}\n";
echo "merged_duplicate_plan_rows={$deletedPlans}\n";
echo "relinked_roomtype_plans={$relinkedPlans}\n";
echo 'remaining_duplicate_links=' . DB::table('roomtype_pricingplan')
    ->selectRaw('roomtype_id, pricingplan_id, COUNT(*) c')
    ->groupBy('roomtype_id', 'pricingplan_id')
    ->having('c', '>', 1)
    ->count() . "\n";
echo 'remaining_duplicate_plan_definitions=' . DB::table('pricing_plans')
    ->selectRaw('NameAr, NameEn, StartDate, EndDate, ActiveType, COUNT(*) c')
    ->groupBy('NameAr', 'NameEn', 'StartDate', 'EndDate', 'ActiveType')
    ->having('c', '>', 1)
    ->count() . "\n";
echo 'total_pricing_plans=' . DB::table('pricing_plans')->count() . "\n";
echo 'total_roomtype_pricingplan=' . DB::table('roomtype_pricingplan')->count() . "\n";
