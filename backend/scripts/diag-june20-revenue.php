<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Reservation;
use App\Models\ReservationDailyCharge;
use App\Services\Reports\ReportQueryService;
use Carbon\Carbon;

$today = Carbon::today()->toDateString();
echo "Today: {$today}\n\n";

echo "=== Overlapping reservations from 2026-06-20 ===\n";
$reservations = Reservation::excludingCancelled()
    ->where('start_date', '<=', '2026-07-31')
    ->where('expire_date', '>=', '2026-06-20')
    ->orderBy('start_date')
    ->get();

foreach ($reservations as $r) {
    $statusLabel = match ((int) $r->reservation_status) {
        1 => 'confirmed',
        2 => 'pending',
        0 => 'unconfirmed',
        default => (string) $r->reservation_status,
    };
    $chargesJun20 = ReservationDailyCharge::where('reservation_id', $r->id)
        ->whereDate('charge_date', '2026-06-20')
        ->count();
    echo "  #{$r->id} [{$statusLabel}] {$r->start_date} -> {$r->expire_date} charges_on_2026-06-20={$chargesJun20}\n";
}

echo "\n=== DB charges on 2026-06-20 (all statuses) ===\n";
$byStatus = ReservationDailyCharge::query()
    ->join('reservations', 'reservation_daily_charges.reservation_id', '=', 'reservations.id')
    ->whereDate('charge_date', '2026-06-20')
    ->selectRaw('reservations.reservation_status, COUNT(*) as c')
    ->groupBy('reservations.reservation_status')
    ->get();
foreach ($byStatus as $row) {
    echo "  status {$row->reservation_status}: {$row->c} charge(s)\n";
}

echo "\n=== Report rows 2026-06-20 .. 2026-07-05 ===\n";
$report = app(ReportQueryService::class)->run('revenue-summary', [
    'start_date' => '2026-06-01',
    'end_date' => '2026-07-31',
]);
foreach ($report['rows'] as $row) {
    $d = $row['charge_date'];
    if ($d >= '2026-06-20' && $d <= '2026-07-05') {
        echo "  {$d}: nights={$row['room_nights']} reservations={$row['reservations']} in_house={$row['in_house']} revenue={$row['revenue']}\n";
    }
}

echo "\n=== Calendar overlap count per day (non-cancelled) ===\n";
$day = Carbon::parse('2026-06-20');
while ($day->lte(Carbon::parse('2026-07-05'))) {
    $d = $day->toDateString();
    $overlap = Reservation::excludingCancelled()
        ->where('start_date', '<=', $d)
        ->where('expire_date', '>=', $d)
        ->count();
    $confirmed = Reservation::where('reservation_status', 1)
        ->where('start_date', '<=', $d)
        ->where('expire_date', '>=', $d)
        ->count();
    echo "  {$d}: calendar={$overlap} confirmed={$confirmed}\n";
    $day->addDay();
}
