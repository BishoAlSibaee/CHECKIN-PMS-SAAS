<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Reservation;

$date = '2026-06-21';

echo "=== All June 2026 reservations ===\n";
foreach (Reservation::excludingCancelled()->where('start_date', '<=', '2026-06-30')->where('expire_date', '>=', '2026-06-01')->orderBy('start_date')->get() as $r) {
    $st = $r->reservation_status;
    echo "  #{$r->id} st={$st} {$r->start_date}->{$r->expire_date}\n";
}

echo "\n=== Starts on {$date} ===\n";
foreach (Reservation::excludingCancelled()->whereDate('start_date', $date)->get() as $r) {
    echo "  #{$r->id} st={$r->reservation_status}\n";
}

echo "\n=== Ends on {$date} (expire_date) ===\n";
foreach (Reservation::excludingCancelled()->whereDate('expire_date', $date)->get() as $r) {
    echo "  #{$r->id} st={$r->reservation_status}\n";
}

echo "\n=== Overlap start<=date AND expire>=date ===\n";
foreach (Reservation::excludingCancelled()->where('start_date', '<=', $date)->where('expire_date', '>=', $date)->get() as $r) {
    echo "  #{$r->id} st={$r->reservation_status}\n";
}

// Calendar API simulation for June 2026
echo "\n=== Calendar API events for June 2026 (overlap filter) ===\n";
$from = '2026-06-01';
$to = '2026-06-30';
$events = Reservation::with(['client', 'reservationRooms.room'])
    ->excludingCancelled()
    ->where('start_date', '<=', $to)
    ->where('expire_date', '>=', $from)
    ->orderBy('start_date')
    ->get();

$on21 = $events->filter(fn ($r) => $r->start_date <= $date && $r->expire_date >= $date);
echo "Events overlapping {$date}: " . $on21->count() . "\n";
foreach ($on21 as $r) {
    $room = $r->reservationRooms->first()?->room?->number ?? '—';
    echo "  #{$r->id} st={$r->reservation_status} room={$room} {$r->start_date}->{$r->expire_date}\n";
}

echo "\nTotal calendar events in June: " . $events->count() . "\n";
