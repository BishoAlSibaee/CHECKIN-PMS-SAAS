<?php

/**
 * Database-level financial & reservation consistency audit.
 * Run: php scripts/data-consistency-audit.php
 * Repair future-dated payments: php scripts/data-consistency-audit.php --repair-payments
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Reservation;
use App\Models\ReservationDailyCharge;
use App\Models\ReservationPay;
use App\Services\Reports\ReportQueryService;
use App\Services\RevenueAccrualService;
use App\Support\ReservationCashQuery;
use App\Support\ReservationRelatedCleanup;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

$today = Carbon::today();
$repairPayments = in_array('--repair-payments', $argv ?? [], true);
$repairJournalOrphans = in_array('--repair-journal-orphans', $argv ?? [], true);
$results = ['pass' => 0, 'fail' => 0, 'warn' => 0];

function line(string $level, string $msg): void
{
    global $results;
    echo strtoupper($level) . ': ' . $msg . PHP_EOL;
    if ($level === 'pass') {
        $results['pass']++;
    } elseif ($level === 'fail') {
        $results['fail']++;
    } else {
        $results['warn']++;
    }
}

echo "=== Data Consistency Audit ===\n";
echo "Today: {$today->toDateString()}\n\n";

// 1) Counts
$resTotal = Reservation::count();
$res2026 = Reservation::whereYear('start_date', 2026)->count();
$charges = ReservationDailyCharge::count();
$confirmed = Reservation::where('reservation_status', 1)->count();
$pending = Reservation::where('reservation_status', 2)->count();
$cancelled = Reservation::where('reservation_status', 3)->count();

echo "--- Inventory ---\n";
echo "Reservations: {$resTotal} (2026: {$res2026}, confirmed: {$confirmed}, pending: {$pending}, cancelled: {$cancelled})\n";
echo "Daily charge rows: {$charges}\n\n";

// 2) Confirmed reservations must have charges & matching base
echo "--- Confirmed reservation contracts ---\n";
$contractFails = 0;
foreach (Reservation::where('reservation_status', 1)->orderBy('id')->get() as $r) {
    $chargeCount = ReservationDailyCharge::where('reservation_id', $r->id)->count();
    if ($chargeCount === 0) {
        line('fail', "Reservation #{$r->id}: confirmed but no daily_charges");
        $contractFails++;
        continue;
    }

    $sumBase = round((float) ReservationDailyCharge::where('reservation_id', $r->id)->sum('base_amount'), 2);
    $basePrice = round((float) $r->base_price, 2);
    $subtotal = round((float) $r->subtotal, 2);
    $expectedTotal = round($subtotal + (float) $r->taxes, 2);
    $total = round((float) $r->total, 2);
    $formula = round($basePrice - (float) $r->discount + (float) $r->extras + (float) $r->penalties, 2);

    if (abs($sumBase - $basePrice) >= 0.02) {
        line('fail', "Reservation #{$r->id}: charge base sum {$sumBase} != base_price {$basePrice}");
        $contractFails++;
    }
    if (abs($formula - $subtotal) >= 0.02) {
        line('fail', "Reservation #{$r->id}: subtotal formula mismatch (subtotal={$subtotal}, calc={$formula})");
        $contractFails++;
    }
    if (abs($expectedTotal - $total) >= 0.02) {
        line('fail', "Reservation #{$r->id}: total mismatch (total={$total}, subtotal+tax={$expectedTotal})");
        $contractFails++;
    }
}
if ($contractFails === 0) {
    line('pass', "All {$confirmed} confirmed reservation(s) satisfy base/subtotal/total contract");
}

// 3) Charge dates inside stay window
echo "\n--- Charge dates vs stay window ---\n";
$outOfWindow = ReservationDailyCharge::query()
    ->join('reservations', 'reservation_daily_charges.reservation_id', '=', 'reservations.id')
    ->where(function ($q) {
        $q->whereColumn('reservation_daily_charges.charge_date', '<', 'reservations.start_date')
            ->orWhereColumn('reservation_daily_charges.charge_date', '>=', 'reservations.expire_date');
    })
    ->select('reservation_daily_charges.id', 'reservation_daily_charges.reservation_id', 'reservation_daily_charges.charge_date', 'reservations.start_date', 'reservations.expire_date')
    ->get();

if ($outOfWindow->isEmpty()) {
    line('pass', 'All daily charges fall within [start_date, expire_date)');
} else {
    foreach ($outOfWindow->take(10) as $row) {
        line('fail', "Charge #{$row->id} res #{$row->reservation_id}: {$row->charge_date} outside {$row->start_date}–{$row->expire_date}");
    }
    if ($outOfWindow->count() > 10) {
        line('fail', '... and ' . ($outOfWindow->count() - 10) . ' more out-of-window charges');
    }
}

// 4) Orphan charges
echo "\n--- Orphan / cancelled reservation charges ---\n";
$orphanCharges = ReservationDailyCharge::query()
    ->leftJoin('reservations', 'reservation_daily_charges.reservation_id', '=', 'reservations.id')
    ->whereNull('reservations.id')
    ->count();
$cancelledCharges = ReservationDailyCharge::query()
    ->join('reservations', 'reservation_daily_charges.reservation_id', '=', 'reservations.id')
    ->where('reservations.reservation_status', 3)
    ->count();

if ($orphanCharges === 0) {
    line('pass', 'No orphan daily charges');
} else {
    line('fail', "{$orphanCharges} orphan daily charge row(s)");
}

$orphanPays = (int) DB::table('reservation_pay')
    ->leftJoin('reservations', 'reservation_pay.reservation_id', '=', 'reservations.id')
    ->whereNull('reservations.id')
    ->count();
if ($orphanPays === 0) {
    line('pass', 'No orphan payment rows');
} else {
    line('fail', "{$orphanPays} orphan payment row(s)");
}

$orphanJournal = ReservationRelatedCleanup::countOrphanJournalLines();
$orphanReservationIds = ReservationRelatedCleanup::collectMissingReservationIdsReferenced();
if ($orphanJournal === 0 && $orphanReservationIds->isEmpty()) {
    line('pass', 'No orphan reservation-related rows');
} elseif ($repairJournalOrphans) {
    $repaired = ReservationRelatedCleanup::purgeOrphansForMissingReservations();
    line('pass', 'Purged orphan reservation data: '.json_encode($repaired));
} else {
    line('fail', "{$orphanJournal} journal line(s) / {$orphanReservationIds->count()} missing reservation id(s) still referenced — run with --repair-journal-orphans or php artisan reservations:purge-orphans");
}

if ($cancelledCharges === 0) {
    line('pass', 'No charges on cancelled reservations');
} else {
    line('warn', "{$cancelledCharges} charge row(s) on cancelled reservations");
}

// 5) Status 2 charges (expected for backfill, excluded from accrual)
echo "\n--- Pending payment (status 2) ---\n";
$status2WithCharges = Reservation::where('reservation_status', 2)
    ->whereIn('id', ReservationDailyCharge::query()->select('reservation_id'))
    ->count();
line('warn', "{$status2WithCharges} pending reservation(s) have daily_charges — excluded from accrual reports by design");

// 6) Future earned vs stored charges
echo "\n--- Earned-through-today vs future charge rows ---\n";
$futureCharges = ReservationDailyCharge::query()
    ->join('reservations', 'reservation_daily_charges.reservation_id', '=', 'reservations.id')
    ->where('reservations.reservation_status', 1)
    ->whereDate('reservation_daily_charges.charge_date', '>', $today->toDateString())
    ->count();
$earnedCharges = ReservationDailyCharge::query()
    ->join('reservations', 'reservation_daily_charges.reservation_id', '=', 'reservations.id')
    ->where('reservations.reservation_status', 1)
    ->whereDate('reservation_daily_charges.charge_date', '<=', $today->toDateString())
    ->count();
line('pass', "Earned charge rows (status 1, ≤ today): {$earnedCharges}");
line('warn', "Future charge rows (status 1, > today): {$futureCharges} — stored for contract pricing, not yet in accrual");

// 7) Report cross-check (earned period YTD)
echo "\n--- Report vs service (2026-01-01 → today) ---\n";
$start = Carbon::parse('2026-01-01');
$end = $today->copy();
$params = ['start_date' => $start->toDateString(), 'end_date' => $end->toDateString()];

$accrual = app(RevenueAccrualService::class)->calculate('total', null, $start, $end, true);
$revSum = app(ReportQueryService::class)->run('revenue-summary', $params);

$serviceTotal = round((float) $accrual['current']['total'], 2);
$serviceNights = (int) $accrual['current']['count'];
$rowRevenue = round(collect($revSum['rows'] ?? [])->sum('revenue'), 2);
$rowEarnedNights = (int) collect($revSum['rows'] ?? [])->sum('earned_room_nights');
$rowBookedNights = (int) collect($revSum['rows'] ?? [])->sum('room_nights');

$summaryTotal = null;
foreach ($revSum['summary'] ?? [] as $item) {
    if (($item['label'] ?? '') === 'Total revenue') {
        $summaryTotal = round((float) $item['value'], 2);
    }
}

if (abs($serviceTotal - $rowRevenue) < 0.10 && abs($serviceTotal - ($summaryTotal ?? 0)) < 0.10) {
    line('pass', "Revenue-summary revenue {$rowRevenue} matches accrual service {$serviceTotal}");
} else {
    line('fail', "Revenue mismatch: rows={$rowRevenue}, summary={$summaryTotal}, service={$serviceTotal}");
}

if ($serviceNights === $rowEarnedNights) {
    line('pass', "Revenue-summary earned room nights {$rowEarnedNights} matches accrual service");
} else {
    line('fail', "Earned room nights mismatch: rows={$rowEarnedNights}, service={$serviceNights}");
}

$summaryBooked = null;
$summaryEarned = null;
foreach ($revSum['summary'] ?? [] as $item) {
    if (($item['label'] ?? '') === 'Room nights (booked)') {
        $summaryBooked = (int) $item['value'];
    }
    if (($item['label'] ?? '') === 'Room nights (earned)') {
        $summaryEarned = (int) $item['value'];
    }
}
if ($summaryBooked !== null && $rowBookedNights === $summaryBooked) {
    line('pass', "Revenue-summary booked nights total {$rowBookedNights}");
}
if ($summaryEarned !== null && $serviceNights === $summaryEarned) {
    line('pass', "Revenue-summary earned nights summary {$summaryEarned}");
}

// 8) Payments sanity
echo "\n--- Payments ---\n";
$payTotal = round((float) ReservationPay::where('type', 0)->sum('pay'), 2);
$refundTotal = round((float) ReservationPay::where('type', 1)->sum('pay'), 2);
$futurePay = ReservationPay::where('created_at', '>', $today->endOfDay()->toDateTimeString())->count();
echo "Payments in: {$payTotal}, refunds: {$refundTotal}\n";
if ($futurePay === 0) {
    line('pass', 'No payments dated after today');
} else {
    if ($repairPayments) {
        $fixed = 0;
        foreach (ReservationPay::whereDate('created_at', '>', $today->toDateString())->get() as $pay) {
            $capped = ReservationCashQuery::capPaymentTimestampToToday(Carbon::parse($pay->created_at));
            DB::table('reservation_pay')->where('id', $pay->id)->update([
                'created_at' => $capped,
                'updated_at' => $capped,
            ]);
            $fixed++;
        }
        line('pass', "Repaired {$fixed} future-dated payment(s) — capped to {$today->toDateString()}");
        $futurePay = 0;
    } else {
        line('warn', "{$futurePay} payment row(s) after today — run with --repair-payments to cap to today");
    }
}

$overpaid = DB::table('reservations')
    ->whereIn('reservation_status', [1, 2])
    ->whereRaw('(SELECT COALESCE(SUM(CASE WHEN type=0 THEN pay ELSE -pay END),0) FROM reservation_pay WHERE reservation_id = reservations.id) > total + 0.02')
    ->count();
if ($overpaid === 0) {
    line('pass', 'No reservation paid more than total');
} else {
    line('warn', "{$overpaid} reservation(s) with net payments > total");
}

// 9) Duplicate charge per room per night
echo "\n--- Duplicate charge lines ---\n";
$dupes = DB::table('reservation_daily_charges')
    ->select('reservation_id', 'room_id', 'charge_date', DB::raw('COUNT(*) as c'))
    ->groupBy('reservation_id', 'room_id', 'charge_date')
    ->having('c', '>', 1)
    ->get();
if ($dupes->isEmpty()) {
    line('pass', 'No duplicate charge lines (same res/room/date)');
} else {
    foreach ($dupes as $d) {
        line('fail', "Duplicate: res #{$d->reservation_id} room #{$d->room_id} on {$d->charge_date} (×{$d->c})");
    }
}

echo "\n=== Summary ===\n";
echo "PASS: {$results['pass']}\n";
echo "WARN: {$results['warn']}\n";
echo "FAIL: {$results['fail']}\n";

exit($results['fail'] > 0 ? 1 : 0);
