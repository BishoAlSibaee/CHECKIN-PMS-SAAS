<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

Carbon\Carbon::setTestNow('2026-06-24');

$svc = app(App\Services\Reports\ReportQueryService::class);
$r = $svc->run('accrual-revenue', ['start_date' => '2026-06-01', 'end_date' => '2026-06-30']);
$rows = collect($r['rows']);

echo 'Total rows: ' . $rows->count() . PHP_EOL;
$byDate = $rows->groupBy('charge_date')->map->count();
echo 'Dates with multiple rows: ' . $byDate->filter(fn ($c) => $c > 1)->count() . PHP_EOL;
echo 'June 19 count: ' . ($byDate['2026-06-19'] ?? 0) . PHP_EOL;
echo 'Sample june 19: ' . json_encode($rows->where('charge_date', '2026-06-19')->take(2)->values()->all(), JSON_UNESCAPED_UNICODE) . PHP_EOL;
$emptyGuest = $rows->filter(fn ($x) => trim((string) ($x['guest'] ?? '')) === '' || ($x['guest'] ?? '') === '—');
echo 'Empty guest rows: ' . $emptyGuest->count() . PHP_EOL;
$withGuest = $rows->first(fn ($x) => trim((string) ($x['guest'] ?? '')) !== '' && ($x['guest'] ?? '') !== '—');
echo 'Sample with guest: ' . json_encode($withGuest, JSON_UNESCAPED_UNICODE) . PHP_EOL;
