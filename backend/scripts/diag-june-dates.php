<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Reservation;
use App\Services\Reports\ReportQueryService;

$report = collect(app(ReportQueryService::class)->run('revenue-summary', [
    'start_date' => '2026-06-01',
    'end_date' => '2026-06-30',
])['rows'])->keyBy('charge_date');

echo "June month list filter (date_from=2026-06-01, date_to=2026-06-30):\n";
$month = Reservation::excludingCancelled()
    ->where('expire_date', '>=', '2026-06-01')
    ->where('start_date', '<=', '2026-06-30')
    ->orderBy('id')
    ->get();
echo "  count={$month->count()}\n";
foreach ($month as $r) {
    echo "    #{$r->id} st={$r->reservation_status} {$r->start_date}->{$r->expire_date}\n";
}
echo "\n";

foreach (['2026-06-15', '2026-06-19', '2026-06-20', '2026-06-21'] as $date) {
    $overlap = Reservation::excludingCancelled()
        ->where('start_date', '<=', $date)
        ->where('expire_date', '>=', $date)
        ->get();
    $listFilter = Reservation::excludingCancelled()
        ->where('expire_date', '>=', $date)
        ->where('start_date', '<=', $date)
        ->get();
    $row = $report->get($date);
    echo "{$date}: calendar={$overlap->count()} list_api={$listFilter->count()} ";
    echo "report active={$row['active_bookings']} booked={$row['room_nights']}\n";
    foreach ($overlap as $r) {
        echo "    #{$r->id} st={$r->reservation_status} {$r->start_date}->{$r->expire_date}\n";
    }
}
