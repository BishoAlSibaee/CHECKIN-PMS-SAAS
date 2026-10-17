<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Room;
use App\Models\RoomtypePricingplan;
use App\Services\PricingEngine;

echo "=== Demo pricing diagnostic ===\n\n";

foreach (\App\Models\RoomType::all() as $rt) {
    echo "RoomType #{$rt->id} {$rt->name_en}\n";
    echo "  min={$rt->Min_daily_price} max={$rt->Max_daily_price} active_type={$rt->active_type}\n";
    $links = RoomtypePricingplan::with('pricingplan')->where('roomtype_id', $rt->id)->get();
    foreach ($links as $l) {
        $p = $l->pricingplan;
        echo "  Plan: {$p->NameEn} | {$p->StartDate} -> {$p->EndDate} | AT={$p->ActiveType} | daily={$l->DailyPrice}\n";
    }
    echo "\n";
}

$scenarios = [
    ['2026-08-01', '2026-08-11', 'Aug 1-11 mixed (user scenario)'],
    ['2026-08-01', '2026-08-31', 'Aug full month'],
    ['2026-05-31', '2026-06-01', 'Default UI dates today'],
];

$room = Room::where('active', 1)->where('room_type_id', 1)->first()
    ?? Room::where('active', 1)->first();
echo "Test room #{$room->number} type_id={$room->room_type_id}\n\n";
$engine = app(PricingEngine::class);

foreach ($scenarios as [$start, $end, $label]) {
    echo "--- {$label} ({$start} -> {$end}) ---\n";
    $lines = $engine->buildDailyBreakdown($room, $start, $end, 0, 0);
    $planN = 0; $roomN = 0;
    foreach ($lines as $l) {
        if ($l['price_source'] === 'plan') $planN++; else $roomN++;
        echo "  {$l['date']} {$l['base_amount']} {$l['price_source']}\n";
    }
    echo "  TOTAL=" . $engine->sumBaseAmount($lines) . " (plan nights={$planN}, room nights={$roomN})\n\n";
}
