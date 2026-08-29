<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

echo "=== Pricing duplication audit ===\n";

if (! Schema::hasTable('roomtype_pricingplan')) {
    echo "roomtype_pricingplan table missing\n";
    exit(1);
}

$linkDupes = DB::table('roomtype_pricingplan')
    ->select('roomtype_id', 'pricingplan_id', DB::raw('COUNT(*) as c'))
    ->groupBy('roomtype_id', 'pricingplan_id')
    ->having('c', '>', 1)
    ->get();

echo 'duplicate_roomtype_plan_links=' . $linkDupes->count() . "\n";
foreach ($linkDupes as $row) {
    echo "  roomtype={$row->roomtype_id} plan={$row->pricingplan_id} count={$row->c}\n";
}

if (Schema::hasTable('pricing_plans')) {
    $planDupes = DB::table('pricing_plans')
        ->select('NameAr', 'NameEn', 'StartDate', 'EndDate', 'ActiveType', DB::raw('COUNT(*) as c'))
        ->groupBy('NameAr', 'NameEn', 'StartDate', 'EndDate', 'ActiveType')
        ->having('c', '>', 1)
        ->get();

    echo 'duplicate_pricing_plan_definitions=' . $planDupes->count() . "\n";
    foreach ($planDupes as $row) {
        echo "  {$row->NameEn} / {$row->NameAr} {$row->StartDate}..{$row->EndDate} count={$row->c}\n";
    }

    $orphanPlans = DB::table('pricing_plans as p')
        ->leftJoin('roomtype_pricingplan as rtp', 'rtp.pricingplan_id', '=', 'p.id')
        ->whereNull('rtp.id')
        ->count();
    echo "orphan_pricing_plans_no_roomtype_link={$orphanPlans}\n";
}

echo "total_roomtype_pricingplan=" . DB::table('roomtype_pricingplan')->count() . "\n";
if (Schema::hasTable('pricing_plans')) {
    echo 'total_pricing_plans=' . DB::table('pricing_plans')->count() . "\n";
}
