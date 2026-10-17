<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Reservation;
use App\Models\ReservationDailyCharge;
use App\Services\Reports\ReportQueryService;
use Carbon\Carbon;

$start = '2026-06-01';
$end = '2026-07-31';
$today = Carbon::today()->toDateString();

echo "Today: {$today}\n\n";

$report = app(ReportQueryService::class)->run('revenue-summary', [
    'start_date' => $start,
    'end_date' => $end,
]);

$reportByDate = collect($report['rows'])->keyBy('charge_date');

echo "=== Report days with room_nights ===\n";
    foreach ($report['rows'] as $row) {
        echo "  {$row['charge_date']}: nights={$row['room_nights']} in_house={$row['in_house']} revenue={$row['revenue']}\n";
    }

echo "\n=== Days with multiple status=1 charges (DB) ===\n";
$multi = ReservationDailyCharge::query()
    ->join('reservations', 'reservation_daily_charges.reservation_id', '=', 'reservations.id')
    ->where('reservations.reservation_status', 1)
    ->whereBetween('reservation_daily_charges.charge_date', [$start, min($end, $today)])
    ->selectRaw('charge_date, COUNT(*) as c, COUNT(DISTINCT reservation_id) as res')
    ->groupBy('charge_date')
    ->having('c', '>', 1)
    ->orderBy('charge_date')
    ->get();

foreach ($multi as $m) {
    $d = Carbon::parse($m->charge_date)->toDateString();
    $rpt = $reportByDate->get($d);
    $rptNights = $rpt['room_nights'] ?? 'MISSING';
    echo "  {$d}: DB charges={$m->c} reservations={$m->res} report_nights={$rptNights}\n";
}

echo "\n=== Days with calendar overlap but NO report row ===\n";
$day = Carbon::parse($start);
$endC = Carbon::parse(min($end, $today));
while ($day->lte($endC)) {
    $d = $day->toDateString();
    $overlap = Reservation::excludingCancelled()
        ->where('start_date', '<=', $d)
        ->where('expire_date', '>=', $d)
        ->count();
    $status1Charges = ReservationDailyCharge::query()
        ->join('reservations', 'reservation_daily_charges.reservation_id', '=', 'reservations.id')
        ->where('reservations.reservation_status', 1)
        ->whereDate('reservation_daily_charges.charge_date', $d)
        ->count();
    $hasReport = $reportByDate->has($d);
    if ($overlap > 0 && !$hasReport) {
        echo "  {$d}: calendar_reservations={$overlap} status1_charges={$status1Charges} report=" . ($hasReport ? 'yes' : 'NO') . "\n";
    }
    $day->addDay();
}
