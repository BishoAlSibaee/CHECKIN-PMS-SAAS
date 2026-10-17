<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

foreach ([26477, 26483, 26478] as $id) {
    $r = \App\Models\Reservation::find($id);
    if (!$r) continue;
    $charges = \App\Models\ReservationDailyCharge::where('reservation_id', $id)->orderBy('charge_date')->pluck('charge_date')->map(fn ($d) => substr((string)$d, 0, 10));
    echo "#{$id} st={$r->reservation_status} {$r->start_date}->{$r->expire_date} charges: {$charges->implode(', ')}\n";
}
