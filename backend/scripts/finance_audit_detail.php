<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Reservation;
use App\Models\ReservationDailyCharge;
use App\Services\RevenueAccrualService;
use Carbon\Carbon;

$revenue = app(RevenueAccrualService::class);

$r6 = Reservation::where('start_date', '2026-07-26')->first();
echo "=== Booking 6 (Jul 26 - Aug 9) ===\n";
echo "ID {$r6->id} base={$r6->base_price} total={$r6->total} status={$r6->reservation_status}\n";

$charges = ReservationDailyCharge::where('reservation_id', $r6->id)
    ->whereBetween('charge_date', ['2026-08-01', '2026-08-31'])
    ->orderBy('charge_date')
    ->get();

foreach ($charges as $c) {
    echo "{$c->charge_date} base={$c->base_amount} src={$c->price_source} in_plan=" . (int) $c->is_in_plan . "\n";
}
echo "Aug nights: {$charges->count()} base sum: " . round($charges->sum('base_amount'), 2) . "\n\n";

foreach (['2026-05-01', '2026-06-01', '2026-07-01', '2026-08-01'] as $start) {
    $s = Carbon::parse($start);
    $e = $s->copy()->endOfMonth();
    $r = $revenue->calculate('total', null, $s, $e, false);
    echo "Month {$s->format('Y-m')}: nights={$r['current']['count']} base={$r['current']['total_base']} revenue={$r['current']['total']} reservations={$r['current']['reservation_count']}\n";
}

echo "\n=== All 2026 reservations ===\n";
foreach (Reservation::whereYear('start_date', 2026)->orderBy('start_date')->get() as $r) {
    $n = ReservationDailyCharge::where('reservation_id', $r->id)->count();
    echo "#{$r->id} {$r->start_date}->{$r->expire_date} status={$r->reservation_status} base={$r->base_price} total={$r->total} nights_db={$n}\n";
}
