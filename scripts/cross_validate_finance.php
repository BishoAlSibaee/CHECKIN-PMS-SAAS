<?php

/**
 * Live cross-validation via services (no HTTP). Run: php scripts/cross_validate_finance.php
 */

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\FinancialDashboardService;
use App\Services\Reports\ReportQueryService;
use App\Services\RevenueAccrualService;
use Carbon\Carbon;

$start = Carbon::parse('2026-08-01');
$end = Carbon::parse('2026-08-31');
$params = ['start_date' => $start->toDateString(), 'end_date' => $end->toDateString()];

function summaryVal(array $data, string $label): ?float
{
    foreach ($data['summary'] ?? [] as $item) {
        if (($item['label'] ?? '') === $label) {
            return is_numeric($item['value']) ? (float) $item['value'] : null;
        }
    }

    return null;
}

$revenue = app(RevenueAccrualService::class);
$dashboard = app(FinancialDashboardService::class);
$reports = app(ReportQueryService::class);

$accrual = $revenue->calculate('total', null, $start, $end, false);
$dash = $dashboard->build($start, $end);
$kpis = $dash['kpis'] ?? [];

$overview = $reports->run('overview', $params);
$accrualRep = $reports->run('accrual-revenue', $params);
$revSum = $reports->run('revenue-summary', $params);
$cashBox = $reports->run('cash-box', $params);
$recon = $reports->run('accrual-cash-reconciliation', $params);

$serviceAccrual = round((float) $accrual['current']['total'], 2);

$rows = [
    ['Source', 'Accrual', 'Cash In', 'Cash Net'],
    ['RevenueAccrualService', $serviceAccrual, '-', '-'],
    ['FinancialDashboardService', $kpis['accrual_total'] ?? 'ERR', $kpis['cash_in'] ?? 'ERR', $kpis['cash_net'] ?? 'ERR'],
    ['report overview', summaryVal($overview, 'Accrual revenue') ?? 'ERR', '-', '-'],
    ['report accrual-revenue', summaryVal($accrualRep, 'Total revenue') ?? 'ERR', '-', '-'],
    ['report revenue-summary', summaryVal($revSum, 'Total revenue') ?? 'ERR', '-', '-'],
    ['report cash-box', '-', summaryVal($cashBox, 'Cash in') ?? 'ERR', summaryVal($cashBox, 'Net') ?? 'ERR'],
    ['report reconciliation', summaryVal($recon, 'Accrual revenue') ?? 'ERR', '-', summaryVal($recon, 'Cash net') ?? 'ERR'],
];

echo "=== Cross-validation Aug 2026 (live DB via services) ===\n\n";
foreach ($rows as $i => $row) {
    if ($i === 0) {
        printf("%-28s %12s %12s %12s\n", $row[0], $row[1], $row[2], $row[3]);
        echo str_repeat('-', 70)."\n";
    } else {
        printf(
            "%-28s %12s %12s %12s\n",
            $row[0],
            is_numeric($row[1]) ? round((float) $row[1], 2) : $row[1],
            is_numeric($row[2]) ? round((float) $row[2], 2) : $row[2],
            is_numeric($row[3]) ? round((float) $row[3], 2) : $row[3]
        );
    }
}

$accrualVals = array_filter([
    $serviceAccrual,
    (float) ($kpis['accrual_total'] ?? -999),
    summaryVal($overview, 'Accrual revenue') ?? -999,
    summaryVal($accrualRep, 'Total revenue') ?? -999,
    summaryVal($revSum, 'Total revenue') ?? -999,
    summaryVal($recon, 'Accrual revenue') ?? -999,
], fn ($v) => $v > -900);

$cashNetVals = array_filter([
    (float) ($kpis['cash_net'] ?? -999),
    summaryVal($cashBox, 'Net') ?? -999,
    summaryVal($recon, 'Cash net') ?? -999,
], fn ($v) => $v > -900);

$cashInVals = array_filter([
    (float) ($kpis['cash_in'] ?? -999),
    summaryVal($cashBox, 'Cash in') ?? -999,
], fn ($v) => $v > -900);

$spread = fn (array $vals) => count($vals) > 1 ? max($vals) - min($vals) : 0;

echo "\nAccrual spread: ".round($spread($accrualVals), 2).($spread($accrualVals) < 0.10 ? ' PASS' : ' MISMATCH')."\n";
echo 'Cash in spread: '.round($spread($cashInVals), 2).($spread($cashInVals) < 0.15 ? ' PASS' : ' MISMATCH')."\n";
echo 'Cash net spread: '.round($spread($cashNetVals), 2).($spread($cashNetVals) < 0.15 ? ' PASS' : ' MISMATCH')."\n";

echo "\nNOT auto-validated against each other:\n";
echo "  accounting: chart-of-accounts, trial-balance, GL, cash-flow\n";
echo "  operational: occupancy, revpar, room-board, ar-aging, arrivals-departures\n";
