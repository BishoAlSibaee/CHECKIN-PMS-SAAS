<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$rows = DB::table('reservation_daily_charges')
    ->join('reservations', 'reservation_daily_charges.reservation_id', '=', 'reservations.id')
    ->whereDate('charge_date', '2026-06-20')
    ->select('reservation_daily_charges.reservation_id', 'reservations.reservation_status', 'reservation_daily_charges.room_id')
    ->get();

echo "Charges on 2026-06-20: {$rows->count()}\n";
foreach ($rows as $x) {
    echo "  res #{$x->reservation_id} status={$x->reservation_status} room={$x->room_id}\n";
}

foreach (['2026-06-15', '2026-06-18', '2026-06-19', '2026-06-20'] as $d) {
    $n = \App\Models\Reservation::excludingCancelled()->where('start_date', '<=', $d)->where('expire_date', '>=', $d)->count();
    $c = DB::table('reservation_daily_charges')->join('reservations', 'reservation_daily_charges.reservation_id', '=', 'reservations.id')->whereDate('charge_date', $d)->where('reservations.reservation_status', 1)->count();
    echo "{$d}: calendar={$n} status1_charges={$c}\n";
}
