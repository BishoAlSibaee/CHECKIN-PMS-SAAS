<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Reservation;
use App\Models\ReservationDailyCharge;
use App\Services\Reports\ReportQueryService;

$days = ['2026-06-08', '2026-06-12', '2026-06-13'];
echo 'Today: ' . now()->toDateString() . "\n\n";

$report = collect(app(ReportQueryService::class)->run('revenue-summary', [
    'start_date' => '2026-06-01',
    'end_date' => '2026-06-30',
])['rows'])->keyBy('charge_date');

foreach ($days as $date) {
    echo "=== {$date} ===\n";
    $row = $report->get($date);
    echo "Report: active={$row['active_bookings']} room_nights={$row['room_nights']} earned={$row['earned_room_nights']} revenue={$row['revenue']}\n";

    $overlap = Reservation::excludingCancelled()->overlappingDate($date)->get();
    echo "Active reservations ({$overlap->count()}):\n";
    foreach ($overlap as $r) {
        $st = match ((int) $r->reservation_status) {
            1 => 'confirmed',
            2 => 'pending payment',
            default => (string) $r->reservation_status,
        };
        $charges = ReservationDailyCharge::where('reservation_id', $r->id)
            ->whereDate('charge_date', $date)->count();
        $allCharges = ReservationDailyCharge::where('reservation_id', $r->id)->orderBy('charge_date')->pluck('charge_date')->toArray();
        echo "  #{$r->id} [{$st}] {$r->start_date}->{$r->expire_date} charges_on_day={$charges}\n";
        echo "    all charge dates: " . implode(', ', $allCharges) . "\n";
    }
    echo "\n";
}
