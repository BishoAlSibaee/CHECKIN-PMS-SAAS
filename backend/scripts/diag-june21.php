<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Reservation;
use App\Models\ReservationDailyCharge;
use App\Services\Reports\ReportQueryService;

$date = '2026-06-21';
$today = now()->toDateString();
echo "Today: {$today}\n";
echo "=== Date: {$date} ===\n\n";

echo "Calendar (non-cancelled overlapping):\n";
$reservations = Reservation::excludingCancelled()
    ->where('start_date', '<=', $date)
    ->where('expire_date', '>=', $date)
    ->orderBy('id')
    ->get();

foreach ($reservations as $r) {
    $status = match ((int) $r->reservation_status) {
        1 => 'confirmed',
        2 => 'pending payment',
        0 => 'unconfirmed',
        default => (string) $r->reservation_status,
    };
    $charges = ReservationDailyCharge::where('reservation_id', $r->id)
        ->whereDate('charge_date', $date)
        ->count();
    echo "  #{$r->id} [{$status}] {$r->start_date} -> {$r->expire_date} charges_on_date={$charges}\n";
}

echo "\nCharges on {$date} by status:\n";
$byStatus = ReservationDailyCharge::query()
    ->join('reservations', 'reservation_daily_charges.reservation_id', '=', 'reservations.id')
    ->whereDate('charge_date', $date)
    ->selectRaw('reservations.reservation_status, reservations.id, COUNT(*) as c')
    ->groupBy('reservations.reservation_status', 'reservations.id')
    ->get();
foreach ($byStatus as $row) {
    echo "  res #{$row->id} status={$row->reservation_status}: {$row->c} charge(s)\n";
}

$report = app(ReportQueryService::class)->run('revenue-summary', [
    'start_date' => '2026-06-01',
    'end_date' => '2026-06-30',
]);
$row = collect($report['rows'])->firstWhere('charge_date', $date);
echo "\nReport row for {$date}:\n";
print_r($row);
