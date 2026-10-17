<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Reservation;
use App\Models\ReservationPay;

$ids = [40637, 40641, 40646, 40647];

foreach ($ids as $id) {
    $r = Reservation::with('payments')->find($id);
    if (!$r) {
        continue;
    }
    echo "\n=== Reservation {$id} ===\n";
    echo "total={$r->total} base={$r->base_price} subtotal={$r->subtotal} tax={$r->taxes}\n";
    echo "stay {$r->start_date} .. {$r->expire_date} status={$r->reservation_status} logedin={$r->logedin}\n";
    echo "balanceDue=" . $r->balanceDue() . "\n";
    foreach ($r->payments->sortBy('created_at') as $p) {
        echo "  pay id={$p->id} type={$p->type} amount={$p->pay} at={$p->created_at}\n";
    }
}
