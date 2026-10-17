<?php

/**
 * Comprehensive API client test — login, clients, reservations, finance, refunds.
 * Usage: php scripts/client_api_test.php [base_url]
 */

$base = rtrim($argv[1] ?? 'http://127.0.0.1:8001/api', '/');
$passed = 0;
$failed = 0;
$warnings = [];

function api(string $method, string $url, ?array $body = null, ?string $token = null): array
{
    $ch = curl_init($url);
    $headers = ['Accept: application/json', 'Content-Type: application/json'];
    if ($token) {
        $headers[] = 'Authorization: Bearer ' . $token;
    }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => $body ? json_encode($body) : null,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 45,
    ]);
    $raw = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($raw === false) {
        return ['code' => 0, 'body' => ['success' => false, 'message' => $err ?: 'connection failed']];
    }

    return ['code' => $code, 'body' => json_decode($raw, true) ?? ['raw' => $raw]];
}

function check(string $label, bool $ok, string $detail = ''): void
{
    global $passed, $failed;
    if ($ok) {
        $passed++;
        echo "  PASS  $label\n";
    } else {
        $failed++;
        echo "  FAIL  $label" . ($detail ? " — $detail" : '') . "\n";
    }
}

function warn(string $msg): void
{
    global $warnings;
    $warnings[] = $msg;
    echo "  WARN  $msg\n";
}

function section(string $title): void
{
    echo "\n--- $title ---\n";
}

echo "========================================\n";
echo "  Hotel API — Client Test (Laravel 12)\n";
echo "  Base: $base\n";
echo "========================================\n";

// ── 1. Auth ──────────────────────────────────────────────────────────
section('1. Authentication');

$badLogin = api('POST', "$base/users/login", ['job_number' => '001', 'password' => 'wrong']);
check('Reject invalid password', ($badLogin['body']['success'] ?? true) === false);

$login = api('POST', "$base/users/login", ['job_number' => '001', 'password' => 'admin123']);
check('Login job 001', ($login['body']['success'] ?? false) === true, $login['body']['message'] ?? 'no response');
$token = $login['body']['data']['token'] ?? '';
check('Bearer token received', $token !== '');

$perms = $login['body']['data']['permissions'] ?? [];
check('Permissions in login response', is_array($perms) && count($perms) > 0);

$me = api('GET', "$base/users/me", null, $token);
check('GET /users/me', ($me['body']['success'] ?? false) === true);
$jobNumber = $me['body']['data']['job_number'] ?? '';
check('Me returns job_number', $jobNumber === '001' || $jobNumber === 1 || $jobNumber === '1');

// ── 2. Property & rooms ──────────────────────────────────────────────
section('2. Property & Rooms');

$buildings = api('GET', "$base/users/buildings", null, $token);
check('GET /users/buildings', ($buildings['body']['success'] ?? false) === true);
$buildingId = $buildings['body']['data'][0]['id'] ?? null;

$rooms = api('GET', "$base/users/rooms" . ($buildingId ? "?building_id=$buildingId" : ''), null, $token);
check('GET /users/rooms', ($rooms['body']['success'] ?? false) === true);

$board = api('GET', "$base/users/rooms/occupancy-board?date=" . date('Y-m-d'), null, $token);
check('GET /users/rooms/occupancy-board', ($board['body']['success'] ?? false) === true);

$roomTypes = api('GET', "$base/users/getRoomType", null, $token);
check('GET /users/getRoomType', ($roomTypes['body']['success'] ?? false) === true);

// ── 3. Clients ───────────────────────────────────────────────────────
section('3. Clients');

$clients = api('GET', "$base/users/getClient", null, $token);
check('GET /users/getClient (list)', ($clients['body']['success'] ?? false) === true);
$clientList = $clients['body']['data'] ?? [];
$firstClient = is_array($clientList) ? ($clientList[0] ?? null) : null;

if ($firstClient && isset($firstClient['id'])) {
    $clientShow = api('GET', "$base/users/getClient/{$firstClient['id']}", null, $token);
    check('GET /users/getClient/{id}', ($clientShow['body']['success'] ?? false) === true);
} else {
    warn('No clients in DB — skipped client detail');
}

// ── 4. Reservations ──────────────────────────────────────────────────
section('4. Reservations');

$reservations = api('GET', "$base/users/reservations", null, $token);
check('GET /users/reservations', ($reservations['body']['success'] ?? false) === true);
$resList = $reservations['body']['data'] ?? [];
$firstRes = null;
if (isset($resList['data']) && is_array($resList['data'])) {
    $firstRes = $resList['data'][0] ?? null;
} elseif (is_array($resList)) {
    $firstRes = $resList[0] ?? null;
}

if ($firstRes && isset($firstRes['id'])) {
    $resId = $firstRes['id'];
    $resShow = api('GET', "$base/users/reservations/$resId", null, $token);
    check("GET /users/reservations/$resId", ($resShow['body']['success'] ?? false) === true);

    $hasRooms = !empty($resShow['body']['data']['room_numbers'])
        || !empty($resShow['body']['data']['room_numbers_label']);
    if ($hasRooms) {
        check('Reservation shows room numbers', true);
    } else {
        warn("Reservation #$resId has no room_numbers in response");
    }
} else {
    warn('No reservations — skipped reservation detail');
}

$calendar = api('GET', "$base/users/reservations/calendar", null, $token);
check('GET /users/reservations/calendar', ($calendar['body']['success'] ?? false) === true);

// ── 5. Refund policies ───────────────────────────────────────────────
section('5. Refund Policies');

$policies = api('GET', "$base/users/refund-policies", null, $token);
check('GET /users/refund-policies', ($policies['body']['success'] ?? false) === true);
$policyCount = is_array($policies['body']['data'] ?? null) ? count($policies['body']['data']) : 0;
check('Refund policies exist', $policyCount > 0, "found $policyCount");

if ($firstRes && isset($firstRes['id'])) {
    $preview = api('GET', "$base/users/refund-policies/preview?reservation_id={$firstRes['id']}", null, $token);
    $previewOk = ($preview['body']['success'] ?? false) === true;
    $previewBlocked = in_array($preview['code'], [422, 403], true);
    check('Refund preview endpoint responds', $previewOk || $previewBlocked,
        $previewOk ? 'policy matched' : ($preview['body']['message'] ?? "HTTP {$preview['code']}"));
}

// ── 6. Finance & reports ───────────────────────────────────────────────
section('6. Finance & Reports');

$monthStart = date('Y-m-01');
$monthEnd = date('Y-m-t');
$q = "?start_date=$monthStart&end_date=$monthEnd";

$dashboard = api('GET', "$base/users/dashboard/summary", null, $token);
check('GET /users/dashboard/summary', ($dashboard['body']['success'] ?? false) === true);

$earnings = api('GET', "$base/users/earnings-summary$q", null, $token);
check('GET /users/earnings-summary', ($earnings['body']['success'] ?? false) === true);

$payments = api('GET', "$base/users/payments$q", null, $token);
check('GET /users/payments', ($payments['body']['success'] ?? false) === true);

$refunds = api('GET', "$base/users/refunds$q", null, $token);
check('GET /users/refunds', ($refunds['body']['success'] ?? false) === true);

$revenue = api('GET', "$base/users/revenue/total$q", null, $token);
check('GET /users/revenue/total', ($revenue['body']['success'] ?? false) === true);

$catalog = api('GET', "$base/users/reports/catalog", null, $token);
check('GET /users/reports/catalog', ($catalog['body']['success'] ?? false) === true);

// ── 7. Users & RBAC ────────────────────────────────────────────────────
section('7. Users & Permissions');

$roles = api('GET', "$base/users/getRoles", null, $token);
check('GET /users/getRoles', ($roles['body']['success'] ?? false) === true);

$permissions = api('GET', "$base/users/getPermissions", null, $token);
check('GET /users/getPermissions', ($permissions['body']['success'] ?? false) === true);

$health = api('GET', "$base/users/system/health", null, $token);
check('GET /users/system/health', ($health['body']['success'] ?? false) === true);

// ── Summary ────────────────────────────────────────────────────────────
echo "\n========================================\n";
echo "  RESULT: $passed passed, $failed failed";
if (count($warnings)) {
    echo ', ' . count($warnings) . ' warnings';
}
echo "\n========================================\n";

if ($failed === 0) {
    echo "  All critical APIs operational.\n";
    echo "  Angular login: job 001 / admin123\n";
    echo "  API URL: $base/\n";
} else {
    echo "  Fix failures before using Angular.\n";
}

exit($failed > 0 ? 1 : 0);
