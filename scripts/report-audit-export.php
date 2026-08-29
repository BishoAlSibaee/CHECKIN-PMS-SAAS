<?php

require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$svc = app(App\Services\Reports\ReportQueryService::class);
$params = ['start_date' => '2026-08-01', 'end_date' => '2026-08-31'];
$slugs = [
    'overview', 'room-board', 'accrual-revenue', 'cash-box', 'arrivals-departures',
    'reservations-list', 'occupancy', 'revenue-summary', 'accrual-cash-reconciliation',
    'ar-aging', 'adjustments', 'tax', 'revpar', 'by-dimension', 'peak-analysis',
    'payments-refunds', 'closing-package', 'chart-of-accounts', 'journal-entries',
    'general-ledger', 'trial-balance', 'cash-flow', 'financial-audit-log',
];

$out = [];
foreach ($slugs as $slug) {
    $data = $svc->run($slug, $params);
    $summary = [];
    foreach ($data['summary'] ?? [] as $s) {
        $summary[$s['label']] = $s['value'];
    }
    $out[$slug] = [
        'rows' => count($data['rows'] ?? []),
        'summary' => $summary,
    ];
}

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
