<?php

/** Light load test: N parallel workers x M requests each */
$api = rtrim($argv[1] ?? 'https://hotelsystemback.osus.network/api', '/');
$workers = (int) ($argv[2] ?? 25);
$perWorker = (int) ($argv[3] ?? 3);

function worker(string $api, int $id, int $count): array
{
    $times = [];
    $errors = 0;

    $login = json_decode(file_get_contents($api.'/users/login', false, stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\nAccept: application/json\r\n",
            'content' => '{"job_number":"001","password":"admin123"}',
            'timeout' => 60,
        ],
    ])), true);

    $token = $login['data']['token'] ?? '';
    if (! $token) {
        return ['worker' => $id, 'errors' => $count, 'times' => []];
    }

    $today = date('Y-m-d');
    $endpoints = [
        "$api/users/dashboard/summary",
        "$api/users/rooms/occupancy-board?date=$today",
        "$api/users/reservations",
    ];

    for ($i = 0; $i < $count; $i++) {
        $url = $endpoints[$i % count($endpoints)];
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ["Authorization: Bearer $token", 'Accept: application/json'],
            CURLOPT_TIMEOUT => 60,
        ]);
        $t = microtime(true);
        $raw = curl_exec($ch);
        $ms = round((microtime(true) - $t) * 1000, 1);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $j = json_decode($raw, true);
        if ($code !== 200 || ! ($j['success'] ?? false)) {
            $errors++;
        } else {
            $times[] = $ms;
        }
    }

    return ['worker' => $id, 'errors' => $errors, 'times' => $times];
}

echo "Load test: $workers workers x $perWorker requests = ".($workers * $perWorker)." total\n";
echo "Target: $api\n\n";

$allTimes = [];
$totalErrors = 0;
$startAll = microtime(true);

// Sequential workers (PHP CLI — simulates staggered users; for true parallel use multiple processes)
for ($w = 1; $w <= $workers; $w++) {
    $r = worker($api, $w, $perWorker);
    $totalErrors += $r['errors'];
    $allTimes = array_merge($allTimes, $r['times']);
    if ($w % 5 === 0) {
        echo "  ... worker $w/$workers done\n";
    }
}

$elapsed = round(microtime(true) - $startAll, 1);
$ok = count($allTimes);
$total = $workers * $perWorker;

sort($allTimes);
$p50 = $allTimes[(int) floor(count($allTimes) * 0.5)] ?? 0;
$p95 = $allTimes[(int) floor(count($allTimes) * 0.95)] ?? 0;
$max = $allTimes ? max($allTimes) : 0;
$avg = $allTimes ? round(array_sum($allTimes) / count($allTimes), 1) : 0;
$rps = $elapsed > 0 ? round($ok / $elapsed, 2) : 0;

echo "\nResults:\n";
echo "  Successful: $ok / $total\n";
echo "  Errors: $totalErrors\n";
echo "  Wall time: {$elapsed}s\n";
echo "  Throughput: {$rps} req/s (successful)\n";
echo "  Latency avg={$avg}ms p50={$p50}ms p95={$p95}ms max={$max}ms\n";

$status = $totalErrors === 0 && $p95 < 8000 ? 'PASS' : ($totalErrors < $total * 0.05 ? 'WARN' : 'FAIL');
echo "  Status: $status\n";
exit($status === 'FAIL' ? 1 : 0);
