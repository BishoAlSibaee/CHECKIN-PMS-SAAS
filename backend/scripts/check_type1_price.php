<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Roomtype;
use App\Models\RoomtypePricingplan;
use App\Services\PricingEngine;
use App\Models\Room;

$engine = app(PricingEngine::class);
$rt = Roomtype::find(1);
echo "RoomType 1: " . ($rt->name_en ?? $rt->NameEn ?? '?') . PHP_EOL;
echo "Daily min/max: {$rt->Min_daily_price} / {$rt->Max_daily_price}" . PHP_EOL;

$links = RoomtypePricingplan::where('roomtype_id', 1)->with('pricingplan')->get();
foreach ($links as $l) {
    $p = $l->pricingplan;
    echo "Plan: " . ($p->NameEn ?? '?') . " {$p->StartDate} -> {$p->EndDate} daily={$l->DailyPrice}" . PHP_EOL;
}

$room = Room::where('room_type_id', 1)->where('active', 1)->first();
if (!$room) {
    echo "No active room for type 1" . PHP_EOL;
    exit(1);
}

$start = '2026-08-01';
$end = '2026-08-11';
$plan = $engine->resolveStayPricingPlan(1, $start, $end);
echo "Resolved plan: " . ($plan ? ($plan->pricingplan->NameEn ?? '?') . " {$plan->pricingplan->StartDate}" : 'null') . PHP_EOL;

$lines = $engine->buildDailyBreakdown($room, $start, $end, 0, 0);
$total = $engine->sumBaseAmount($lines);
$segments = $engine->buildPriceSegments($lines, 0);

echo "Total: {$total}" . PHP_EOL;
foreach ($lines as $line) {
    echo "  {$line['date']}  {$line['base_amount']}  {$line['billing_source']}" . PHP_EOL;
}
echo "Segments:" . PHP_EOL;
foreach ($segments as $seg) {
    echo "  {$seg['from']} -> {$seg['to']}  {$seg['billing_source']}  {$seg['amount']} ({$seg['nights']} nights)" . PHP_EOL;
}
