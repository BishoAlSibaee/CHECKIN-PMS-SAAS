<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Reservation;
use App\Models\ReservationDailyCharge;

$r = Reservation::find(26478);
if (!$r) {
    echo "26478 not found\n";
    exit;
}

echo "Reservation #{$r->id} status={$r->reservation_status} {$r->start_date} -> {$r->expire_date}\n";
$charges = ReservationDailyCharge::where('reservation_id', $r->id)->orderBy('charge_date')->pluck('charge_date')->map(fn ($d) => (string) $d);
echo 'Charge dates (' . $charges->count() . "): " . $charges->implode(', ') . "\n";

echo "\nReservations touching 2026-06-20 (start, end, or spanning):\n";
foreach (Reservation::excludingCancelled()->where('start_date', '<=', '2026-06-20')->where('expire_date', '>=', '2026-06-20')->get() as $x) {
    echo "  #{$x->id} status={$x->reservation_status} {$x->start_date}->{$x->expire_date}\n";
}
foreach (Reservation::excludingCancelled()->whereDate('start_date', '2026-06-20')->orWhereDate('expire_date', '2026-06-20')->get() as $x) {
    echo "  (edge) #{$x->id} status={$x->reservation_status} {$x->start_date}->{$x->expire_date}\n";
}
