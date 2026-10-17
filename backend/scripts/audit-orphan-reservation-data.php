<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Reservation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

$checkId = isset($argv[1]) ? (int) $argv[1] : 32700;

echo "=== Orphan reservation data audit ===\n";
echo "Today: " . now()->toDateString() . "\n";
echo "Check reservation #{$checkId}: " . (Reservation::find($checkId) ? 'EXISTS' : 'MISSING') . "\n";
echo 'Reservation id range: min=' . Reservation::min('id') . ' max=' . Reservation::max('id') . ' count=' . Reservation::count() . "\n\n";

$tables = [
    'reservation_pay',
    'reservation_daily_charges',
    'reservation_rooms',
    'reservation_extend',
    'reservation_penalties',
    'reservation_taxes',
];

if (Schema::hasTable('journal_entry_lines')) {
    $tables[] = 'journal_entry_lines';
}
if (Schema::hasTable('financial_audit_logs')) {
    $tables[] = 'financial_audit_logs';
}

foreach ($tables as $table) {
    if (!Schema::hasTable($table)) {
        echo "SKIP {$table} (no table)\n";
        continue;
    }

    $col = 'reservation_id';
    if (!Schema::hasColumn($table, $col)) {
        echo "SKIP {$table} (no reservation_id column)\n";
        continue;
    }

    $orphanCount = (int) DB::table($table)
        ->leftJoin('reservations', "{$table}.reservation_id", '=', 'reservations.id')
        ->whereNull('reservations.id')
        ->count();

    $orphanForId = (int) DB::table($table)->where('reservation_id', $checkId)->count();

    echo str_pad($table, 28) . " orphans={$orphanCount}  rows_for #{$checkId}={$orphanForId}\n";

    if ($orphanCount > 0) {
        $sample = DB::table($table)
            ->leftJoin('reservations', "{$table}.reservation_id", '=', 'reservations.id')
            ->whereNull('reservations.id')
            ->select("{$table}.id", "{$table}.reservation_id")
            ->limit(8)
            ->get();
        foreach ($sample as $row) {
            echo "    orphan row id={$row->id} reservation_id={$row->reservation_id}\n";
        }
        if ($orphanCount > 8) {
            echo '    ... +' . ($orphanCount - 8) . " more\n";
        }
    }
}

echo "\n--- Payments referencing missing reservations (detail) ---\n";
$orphanPays = DB::table('reservation_pay')
    ->leftJoin('reservations', 'reservation_pay.reservation_id', '=', 'reservations.id')
    ->whereNull('reservations.id')
    ->select('reservation_pay.*')
    ->limit(15)
    ->get();

if ($orphanPays->isEmpty()) {
    echo "None — all payments link to existing reservations.\n";
} else {
    foreach ($orphanPays as $p) {
        echo "  pay #{$p->id} res={$p->reservation_id} amount={$p->pay} type={$p->type} at={$p->created_at}\n";
    }
}

echo "\n--- Journal lines with reservation_id but missing reservation ---\n";
if (Schema::hasTable('journal_entry_lines')) {
    $orphanJournal = DB::table('journal_entry_lines')
        ->leftJoin('reservations', 'journal_entry_lines.reservation_id', '=', 'reservations.id')
        ->whereNotNull('journal_entry_lines.reservation_id')
        ->whereNull('reservations.id')
        ->count();
    echo "Orphan journal lines: {$orphanJournal}\n";
} else {
    echo "No journal_entry_lines table\n";
}
