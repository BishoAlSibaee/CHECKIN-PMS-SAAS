<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Reservation;

echo "June 2026 reservations:\n";
foreach (Reservation::excludingCancelled()->where('start_date', '<=', '2026-06-30')->where('expire_date', '>=', '2026-06-01')->orderBy('start_date')->get() as $r) {
    echo "  #{$r->id} st={$r->reservation_status} {$r->start_date}->{$r->expire_date}\n";
}
