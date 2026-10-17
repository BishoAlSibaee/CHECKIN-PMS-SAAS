<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Reservation;

$id = (int) ($argv[1] ?? 1748);
$r = Reservation::with(['reservationRooms.room.roomType', 'client'])->find($id);

if (!$r) {
    echo "NOT_FOUND\n";
    exit(1);
}

$arr = $r->toArray();
echo "reservation_rooms key: " . (isset($arr['reservation_rooms']) ? 'yes' : 'no') . "\n";
echo "reservationRooms key: " . (isset($arr['reservationRooms']) ? 'yes' : 'no') . "\n";
echo "count: " . count($arr['reservation_rooms'] ?? $arr['reservationRooms'] ?? []) . "\n";
$rooms = $arr['reservation_rooms'] ?? $arr['reservationRooms'] ?? [];
foreach ($rooms as $rr) {
    $room = $rr['room'] ?? null;
    echo json_encode([
        'rr_id' => $rr['id'] ?? null,
        'room_id' => $rr['room_id'] ?? null,
        'room_number' => $room['number'] ?? null,
        'room_keys' => $room ? array_keys($room) : [],
    ]) . "\n";
}

$ctrl = app(\App\Http\Controllers\ReservationController::class);
$resp = $ctrl->show($id);
$json = json_decode($resp->getContent(), true);
$res = $json['data']['reservation'] ?? [];
$apiRooms = $res['reservation_rooms'] ?? $res['reservationRooms'] ?? [];
echo "\nAPI reservation_rooms count: " . count($apiRooms) . "\n";
echo "API first room number: " . ($apiRooms[0]['room']['number'] ?? 'MISSING') . "\n";
$resKeys = array_keys($res);
echo "Reservation JSON keys sample: " . implode(', ', array_slice($resKeys, 0, 20)) . "\n";
echo "Has reservation_rooms: " . (array_key_exists('reservation_rooms', $res) ? 'yes' : 'no') . "\n";
echo "Has reservationRooms: " . (array_key_exists('reservationRooms', $res) ? 'yes' : 'no') . "\n";
$rt = $apiRooms[0]['room']['room_type'] ?? null;
echo "room_type: " . json_encode($rt) . "\n";
