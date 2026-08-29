<?php

/**
 * Localhost benchmark — API must be running on 127.0.0.1:8001 with Redis enabled.
 *
 * Usage: php scripts/local-benchmark.php
 */
$api = getenv('BENCHMARK_API_URL') ?: 'http://127.0.0.1:8001/api';
$monthStart = '2026-08-01';
$monthEnd = '2026-08-31';

function localApi(string $method, string $url, ?array $body = null, ?string $token = null): array
{
    $ch = curl_init($url);
    $headers = ['Accept: application/json', 'Content-Type: application/json'];
    if ($token) {
        $headers[] = 'Authorization: Bearer '.$token;
    }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => $body ? json_encode($body) : null,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 120,
    ]);
    $t = microtime(true);
    $raw = curl_exec($ch);
    $ms = round((microtime(true) - $t) * 1000, 1);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    return [
        'code' => $code,
        'ms' => $ms,
        'body' => json_decode((string) $raw, true),
        'error' => $err,
    ];
}

$health = localApi('GET', "$api/users/setup-check");
if ($health['code'] === 0) {
    fwrite(STDERR, "API not reachable at $api — start: php artisan serve --host=127.0.0.1 --port=8001\n");
    exit(1);
}

$login = localApi('POST', "$api/users/login", ['job_number' => '001', 'password' => 'admin123']);
$token = $login['body']['data']['token'] ?? '';
if ($token === '') {
    fwrite(STDERR, "Login failed (HTTP {$login['code']}).\n");
    exit(1);
}

$targets = [
    'dashboard' => "$api/users/dashboard/summary",
    'collections-summary' => "$api/users/collections/summary",
    'occupancy-board' => "$api/users/rooms/occupancy-board?date=2026-08-10",
    'occupancy-report' => "$api/users/reports/occupancy?start_date=$monthStart&end_date=$monthEnd",
    'room-board-report' => "$api/users/reports/room-board?end_date=2026-08-10",
];

echo "Local benchmark ($api) — 3 runs each; runs 2–3 show cache hits\n";
echo str_repeat('-', 60)."\n";

foreach ($targets as $name => $url) {
    $times = [];
    for ($i = 0; $i < 3; $i++) {
        $r = localApi('GET', $url, null, $token);
        if ($r['code'] !== 200) {
            echo sprintf("%-20s HTTP %d %s ms\n", $name, $r['code'], $r['ms']);

            continue 2;
        }
        $times[] = $r['ms'];
    }
    $avg = round(array_sum($times) / count($times), 1);
    echo sprintf("%-20s %s ms (avg %s)\n", $name, implode(', ', $times), $avg);
}

echo str_repeat('-', 60)."\n";
echo "Tip: run twice after code changes; compare occupancy-report run 2 vs run 1.\n";
