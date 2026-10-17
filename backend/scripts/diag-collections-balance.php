<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Reservation;
use App\Models\ReservationDailyCharge;
use App\Services\CollectionsService;
use Carbon\Carbon;

$summary = app(CollectionsService::class)->summarize(Carbon::today());
echo "Collections count: {$summary['count']} total: {$summary['total_balance']}\n";

$rows = Reservation::query()
    ->with('payments')
    ->excludingCancelled()
    ->whereIn('reservation_status', Reservation::cashReportStatuses())
    ->get()
    ->filter(fn (Reservation $r) => $r->balanceDue() > 0.005);

foreach ($rows->take(20) as $r) {
    $chargeSum = (float) ReservationDailyCharge::query()
        ->where('reservation_id', $r->id)
        ->sum('base_amount');
    echo sprintf(
        "ID %d status=%d total=%.2f paid=%.2f due=%.2f charges_base=%.2f stay=%s..%s logedin=%d\n",
        $r->id,
        $r->reservation_status,
        (float) $r->total,
        $r->paidNetAmount(),
        $r->balanceDue(),
        $chargeSum,
        $r->start_date,
        $r->expire_date,
        (int) $r->logedin
    );
}

echo 'Total with balance: ' . $rows->count() . "\n";
