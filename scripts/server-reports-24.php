<?php

$api = rtrim($argv[1] ?? 'https://hotelsystemback.osus.network/api', '/');
$login = json_decode(file_get_contents($api.'/users/login', false, stream_context_create([
    'http' => [
        'method' => 'POST',
        'header' => "Content-Type: application/json\r\nAccept: application/json\r\n",
        'content' => '{"job_number":"001","password":"admin123"}',
        'timeout' => 30,
    ],
])), true);
$token = $login['data']['token'] ?? '';
if (! $token) {
    fwrite(STDERR, "Login failed\n");
    exit(1);
}

$slugs = [
    'overview', 'room-board', 'arrivals-departures', 'reservations-list', 'occupancy',
    'accrual-revenue', 'cash-box', 'revenue-summary', 'accrual-cash-reconciliation', 'ar-aging',
    'adjustments', 'tax', 'revpar', 'by-dimension', 'peak-analysis', 'payments-refunds',
    'closing-package', 'chart-of-accounts', 'journal-entries', 'general-ledger', 'trial-balance',
    'cash-flow', 'financial-audit-log',
];
$start = date('Y-m-01');
$end = date('Y-m-t');
$pass = 0;
$fail = 0;
$slow = [];

foreach ($slugs as $slug) {
    $url = "$api/users/reports/$slug?start_date=$start&end_date=$end";
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ["Authorization: Bearer $token", 'Accept: application/json'],
        CURLOPT_TIMEOUT => 120,
    ]);
    $t = microtime(true);
    $raw = curl_exec($ch);
    $ms = round((microtime(true) - $t) * 1000, 1);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $j = json_decode($raw, true);
    $ok = $code === 200 && ($j['success'] ?? false);
    echo ($ok ? 'PASS' : 'FAIL')."  $slug  HTTP $code  {$ms}ms\n";
    if ($ok) {
        $pass++;
    } else {
        $fail++;
    }
    if ($ms > 3000) {
        $slow[] = "$slug {$ms}ms";
    }
}

echo "\nSummary: $pass/24 PASS, $fail FAIL\n";
if ($slow) {
    echo 'Slow (>3s): '.implode(', ', $slow)."\n";
}
exit($fail > 0 ? 1 : 0);
