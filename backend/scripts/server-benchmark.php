<?php

$api = 'https://hotelsystemback.osus.network/api';
$monthStart = '2026-08-01';
$monthEnd = '2026-08-31';

function api(string $method, string $url, ?array $body = null, ?string $token = null): array
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
    ]);
    $t = microtime(true);
    $raw = curl_exec($ch);
    $ms = round((microtime(true) - $t) * 1000, 1);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return ['code' => $code, 'ms' => $ms, 'body' => json_decode($raw, true)];
}

$login = api('POST', "$api/users/login", ['job_number' => '001', 'password' => 'admin123']);
$token = $login['body']['data']['token'] ?? '';

$targets = [
    'dashboard' => "$api/users/dashboard/summary",
    'collections-summary' => "$api/users/collections/summary",
    'occupancy-board' => "$api/users/rooms/occupancy-board?date=2026-08-10",
    'occupancy-report' => "$api/users/reports/occupancy?start_date=$monthStart&end_date=$monthEnd",
    'room-board-report' => "$api/users/reports/room-board?end_date=2026-08-10",
];

echo "Benchmark (3 runs each — 2nd/3rd runs show Redis cache hits)\n";
foreach ($targets as $name => $url) {
    $times = [];
    for ($i = 0; $i < 3; $i++) {
        $r = api('GET', $url, null, $token);
        $times[] = $r['ms'];
    }
    $avg = round(array_sum($times) / count($times), 1);
    echo sprintf("%-18s %s ms (avg %s)\n", $name, implode(', ', $times), $avg);
}
