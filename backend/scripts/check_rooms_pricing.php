<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$rooms = \App\Models\Room::with('roomType')->where('active', 1)->limit(10)->get();
foreach ($rooms as $room) {
    $rt = $room->roomType;
    echo "Room {$room->id} #{$room->number} type={$room->room_type_id} min={$rt->Min_daily_price} max={$rt->Max_daily_price} active_type={$rt->active_type}\n";
}
