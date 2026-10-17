<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$r = \App\Models\Reservation::find(18);
$lines = \App\Models\ReservationDailyCharge::where('reservation_id', 18)->get();
echo "base={$r->base_price} discount={$r->discount} subtotal={$r->subtotal} total={$r->total}\n";
foreach ($lines as $l) {
    echo "{$l->charge_date} {$l->base_amount}\n";
}
