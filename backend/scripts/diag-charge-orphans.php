<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Reservation;
use App\Models\ReservationDailyCharge;

foreach ([40637, 40641, 40646, 40647] as $id) {
    $r = Reservation::with('reservationRooms')->find($id);
    if (!$r) {
        continue;
    }
    $roomIds = $r->reservationRooms->pluck('id')->all();
    $charges = ReservationDailyCharge::where('reservation_id', $id)->get();
    $orphan = $charges->whereNotIn('reservation_room_id', $roomIds);
    echo "Reservation {$id}: rooms=" . count($roomIds) . " charges={$charges->count()} orphan={$orphan->count()} base_sum=" . $charges->sum('base_amount') . "\n";
}
