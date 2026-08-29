<?php

/**
 * Comprehensive remote QA against live/staging API + SPA.
 * Usage: php scripts/server-remote-qa.php [api_base] [spa_base]
 */
$apiBase = rtrim($argv[1] ?? 'https://hotelsystemback.osus.network/api', '/');
$spaBase = rtrim($argv[2] ?? 'https://hotelsystem.osus.network', '/');

$passed = 0;
$failed = 0;
$warned = 0;
$timings = [];
$loadResults = [];

function section(string $title): void
{
    echo "\n".str_repeat('=', 60)."\n  $title\n".str_repeat('=', 60)."\n";
}

function check(string $label, bool $ok, string $detail = ''): void
{
    global $passed, $failed;
    if ($ok) {
        $passed++;
        echo "  PASS  $label".($detail ? " ($detail)" : '')."\n";
    } else {
        $failed++;
        echo "  FAIL  $label".($detail ? " — $detail" : '')."\n";
    }
}

function warn(string $label, string $detail = ''): void
{
    global $warned;
    $warned++;
    echo "  WARN  $label".($detail ? " — $detail" : '')."\n";
}

function api(string $method, string $url, ?array $body = null, ?string $token = null, int $timeout = 45, ?string $origin = null): array
{
    global $timings;

    $ch = curl_init($url);
    $headers = ['Accept: application/json', 'Content-Type: application/json'];
    if ($token) {
        $headers[] = 'Authorization: Bearer '.$token;
    }
    if ($origin) {
        $headers[] = 'Origin: '.$origin;
    }

    $start = microtime(true);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => $body ? json_encode($body) : null,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_HEADER => true,
    ]);
    $raw = curl_exec($ch);
    $elapsed = round((microtime(true) - $start) * 1000, 1);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $err = curl_error($ch);
    curl_close($ch);

    $timings[] = ['url' => preg_replace('#\?.*#', '', $url), 'ms' => $elapsed, 'code' => $code];

    if ($raw === false) {
        return ['code' => 0, 'headers' => '', 'body' => ['success' => false, 'message' => $err ?: 'connection failed'], 'ms' => $elapsed];
    }

    $headerStr = substr($raw, 0, $headerSize);
    $bodyStr = substr($raw, $headerSize);

    return [
        'code' => $code,
        'headers' => $headerStr,
        'body' => json_decode($bodyStr, true) ?? ['raw' => substr($bodyStr, 0, 200)],
        'ms' => $elapsed,
    ];
}

function spaGet(string $path): array
{
    global $timings;
    $url = rtrim($GLOBALS['spaBase'], '/').'/'.ltrim($path, '/');
    $ch = curl_init($url);
    $start = microtime(true);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_NOBODY => false,
    ]);
    $raw = curl_exec($ch);
    $elapsed = round((microtime(true) - $start) * 1000, 1);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $timings[] = ['url' => $url, 'ms' => $elapsed, 'code' => $code];

    return ['code' => $code, 'body' => $raw === false ? '' : $raw, 'ms' => $elapsed];
}

echo "SERVER REMOTE QA\nAPI: $apiBase\nSPA: $spaBase\nDate: ".date('c')."\n";

// ── 1. Infrastructure ───────────────────────────────────────────────
section('1. Infrastructure & Security');

$spaOrigin = parse_url($spaBase, PHP_URL_SCHEME).'://'.parse_url($spaBase, PHP_URL_HOST);

$setup = api('GET', "$apiBase/users/setup-check", null, null, 45, $spaOrigin);
check('setup-check HTTP 200', $setup['code'] === 200, "{$setup['ms']}ms");
check('setup-check success', ($setup['body']['success'] ?? false) === true);
$ready = $setup['body']['data']['ready'] ?? false;
check('setup ready flag', $ready === true, $ready ? 'ready' : 'not ready');

$checks = $setup['body']['data']['checks'] ?? [];
foreach ($checks as $c) {
    $name = $c['name'] ?? '?';
    $status = $c['status'] ?? '?';
    if ($status !== 'pass') {
        warn("setup check: $name", $c['message'] ?? $status);
    }
}

$corsOk = str_contains($setup['headers'] ?? '', 'Access-Control-Allow-Origin')
    || str_contains($setup['headers'] ?? '', 'access-control-allow-origin');
check('CORS header present', $corsOk, $spaOrigin);

$rateProbe = api('GET', "$apiBase/users/setup-check", null, null, 45, $spaOrigin);
check('Rate limit headers', str_contains($rateProbe['headers'] ?? '', 'X-RateLimit-Limit')
    || str_contains($rateProbe['headers'] ?? '', 'x-ratelimit-limit'));

// ── 2. Auth ─────────────────────────────────────────────────────────
section('2. Authentication');

$bad = api('POST', "$apiBase/users/login", ['job_number' => '001', 'password' => 'wrong']);
check('Reject bad password', ($bad['body']['success'] ?? true) === false);

$login = api('POST', "$apiBase/users/login", ['job_number' => '001', 'password' => 'admin123']);
check('Login 001/admin123', ($login['body']['success'] ?? false) === true, "{$login['ms']}ms");
$token = $login['body']['data']['token'] ?? '';
check('Bearer token', $token !== '');
$permCount = count($login['body']['data']['permissions'] ?? []);
check('Permissions in login', $permCount > 5, "$permCount permissions");

// ── 3. Site smoke (GET endpoints) ───────────────────────────────────
section('3. API Smoke (SiteSmoke parity)');

$monthStart = date('Y-m-01');
$monthEnd = date('Y-m-t');
$today = date('Y-m-d');

$smokeEndpoints = [
    'buildings' => '/users/buildings',
    'floors' => '/users/floors?building_id=1',
    'rooms' => '/users/rooms?building_id=1',
    'room types' => '/users/getRoomType',
    'stay reasons' => '/users/getStayReasons',
    'clients' => '/users/getClient',
    'reservations' => '/users/reservations',
    'calendar' => '/users/reservations/calendar',
    'dashboard' => '/users/dashboard/summary',
    'occupancy board' => "/users/rooms/occupancy-board?date=$today",
    'booking availability' => "/users/booking-room-availability?building_id=1&start_date=$today&expire_date=".date('Y-m-d', strtotime('+2 days')),
    'earnings' => "/users/earnings-summary?start_date=$monthStart&end_date=$monthEnd",
    'payments' => "/users/payments?start_date=$monthStart&end_date=$monthEnd",
    'refunds' => "/users/refunds?start_date=$monthStart&end_date=$monthEnd",
    'revenue total' => "/users/revenue/total?start_date=$monthStart&end_date=$monthEnd",
    'reports catalog' => '/users/reports/catalog',
    'chart of accounts' => '/users/accounting/chart-of-accounts',
    'journal entries' => "/users/accounting/journal-entries?start_date=$monthStart&end_date=$monthEnd",
    'refund policies' => '/users/refund-policies',
    'roles' => '/users/getRoles',
    'system health' => '/users/system/health',
    'taxes' => '/users/getTax',
    'discounts' => '/users/getDiscounts',
    'peak days' => '/users/getPeakDays',
];

foreach ($smokeEndpoints as $name => $path) {
    $r = api('GET', $apiBase.$path, null, $token);
    $ok = $r['code'] === 200 && ($r['body']['success'] ?? false) === true;
    check("GET $name", $ok, "HTTP {$r['code']} {$r['ms']}ms");
}

// ── 4. Data consistency ─────────────────────────────────────────────
section('4. Data Consistency (cross-API)');

$dash = api('GET', "$apiBase/users/dashboard/summary", null, $token);
$board = api('GET', "$apiBase/users/rooms/occupancy-board?date=$today", null, $token);

$dashOcc = $dash['body']['data']['occupancy'] ?? [];
$boardSum = $board['body']['data']['summary'] ?? $board['body']['data']['data']['summary'] ?? [];

$totalMatch = (int) ($dashOcc['total'] ?? -1) === (int) ($boardSum['total'] ?? -2);
check('Dashboard total rooms = board total', $totalMatch,
    'dash='.($dashOcc['total'] ?? '?').' board='.($boardSum['total'] ?? '?'));

$rateDash = round((float) ($dashOcc['occupancy_rate'] ?? 0), 1);
$rateBoard = round((float) ($boardSum['occupancy_rate'] ?? 0), 1);
check('Occupancy rate match', abs($rateDash - $rateBoard) < 0.2, "dash=$rateDash% board=$rateBoard%");

$inHouseMatch = (int) ($dashOcc['in_house'] ?? -1) === (int) ($boardSum['in_house'] ?? -2);
check('In-house count match', $inHouseMatch,
    'dash='.($dashOcc['in_house'] ?? '?').' board='.($boardSum['in_house'] ?? '?'));

// Sample reports
$reportSlugs = ['overview', 'occupancy', 'trial-balance', 'revenue-summary'];
foreach ($reportSlugs as $slug) {
    $r = api('GET', "$apiBase/users/reports/$slug?start_date=$monthStart&end_date=$monthEnd", null, $token, 90);
    $ok = $r['code'] === 200 && ($r['body']['success'] ?? false) === true;
    check("Report: $slug", $ok, "HTTP {$r['code']} {$r['ms']}ms");
    if ($ok && empty($r['body']['data'])) {
        warn("Report $slug empty data payload");
    }
}

$catalog = api('GET', "$apiBase/users/reports/catalog", null, $token);
$reports = $catalog['body']['data']['reports'] ?? $catalog['body']['data'] ?? [];
$catCount = is_array($reports) ? count($reports) : 0;
check('Reports catalog has 24 slugs', $catCount >= 24, "found $catCount");

// ── 5. SPA ──────────────────────────────────────────────────────────
section('5. SPA (Angular)');

$index = spaGet('/');
check('SPA index HTTP 200', $index['code'] === 200, "{$index['ms']}ms");
check('SPA has app-root', str_contains($index['body'], '<app-root>'));
check('SPA has main.js bundle', (bool) preg_match('/main\.[a-f0-9]+\.js/', $index['body']));

if (preg_match('/main\.([a-f0-9]+)\.js/', $index['body'], $m)) {
    echo "  INFO  Live main.js hash: main.{$m[1]}.js\n";
}

$spaRoutes = ['/#/dashboard', '/#/booking/reservations', '/#/dashboard/room-board', '/#/admin/pricing', '/#/financials', '/#/reports'];
foreach ($spaRoutes as $route) {
    $r = spaGet($route);
    check("SPA route $route", $r['code'] === 200, "{$r['ms']}ms");
}

// ── 6. Load / concurrency probe ─────────────────────────────────────
section('6. Load & Concurrency (light probe)');

$concurrency = 15;
$loginTimes = [];
$dashTimes = [];
$errors = 0;

for ($i = 0; $i < $concurrency; $i++) {
    $l = api('POST', "$apiBase/users/login", ['job_number' => '001', 'password' => 'admin123']);
    if (($l['body']['success'] ?? false) !== true) {
        $errors++;

        continue;
    }
    $loginTimes[] = $l['ms'];
    $t = $l['body']['data']['token'] ?? '';
    if ($t) {
        $d = api('GET', "$apiBase/users/dashboard/summary", null, $t);
        if (($d['body']['success'] ?? false) !== true) {
            $errors++;
        } else {
            $dashTimes[] = $d['ms'];
        }
    }
}

check("Concurrent logins ($concurrency)", $errors === 0, "$errors errors");

if ($loginTimes) {
    sort($loginTimes);
    $p95 = $loginTimes[(int) floor(count($loginTimes) * 0.95)] ?? max($loginTimes);
    $avg = round(array_sum($loginTimes) / count($loginTimes), 1);
    echo "  INFO  Login: avg={$avg}ms p95={$p95}ms max=".max($loginTimes)."ms\n";
    check('Login p95 < 3000ms', $p95 < 3000, "{$p95}ms");
}

if ($dashTimes) {
    sort($dashTimes);
    $p95 = $dashTimes[(int) floor(count($dashTimes) * 0.95)] ?? max($dashTimes);
    $avg = round(array_sum($dashTimes) / count($dashTimes), 1);
    echo "  INFO  Dashboard: avg={$avg}ms p95={$p95}ms max=".max($dashTimes)."ms\n";
    check('Dashboard p95 < 5000ms', $p95 < 5000, "{$p95}ms");
}

// Burst occupancy board
$burst = 10;
$burstErrors = 0;
$burstTimes = [];
for ($i = 0; $i < $burst; $i++) {
    $b = api('GET', "$apiBase/users/rooms/occupancy-board?date=$today", null, $token);
    if (($b['body']['success'] ?? false) !== true) {
        $burstErrors++;
    } else {
        $burstTimes[] = $b['ms'];
    }
}
check("Burst occupancy-board x$burst", $burstErrors === 0, "$burstErrors failures");
if ($burstTimes) {
    $avg = round(array_sum($burstTimes) / count($burstTimes), 1);
    echo "  INFO  Board burst avg={$avg}ms max=".max($burstTimes)."ms\n";
}

// ── 7. Summary stats ────────────────────────────────────────────────
section('7. Timing Summary');

$slow = array_filter($timings, fn ($t) => $t['ms'] > 2000);
if ($slow) {
    warn('Slow endpoints (>2s)', count($slow).' requests');
    foreach (array_slice($slow, 0, 5) as $s) {
        echo "        {$s['url']} — {$s['ms']}ms\n";
    }
} else {
    check('All probed endpoints < 2s', true);
}

$codes = array_count_values(array_column($timings, 'code'));
echo '  INFO  HTTP codes: '.json_encode($codes)."\n";

section('FINAL RESULT');
echo "  PASS: $passed\n";
echo "  FAIL: $failed\n";
echo "  WARN: $warned\n";

$score = $passed + $failed > 0 ? round(100 * $passed / ($passed + $failed), 1) : 0;
echo "  SCORE: {$score}%\n";

exit($failed > 0 ? 1 : 0);
