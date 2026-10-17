<?php

$base = $argv[1] ?? 'http://127.0.0.1:8001/api';
$passed = 0;
$failed = 0;

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
        CURLOPT_TIMEOUT => 30,
    ]);
    $raw = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return ['code' => $code, 'body' => json_decode($raw, true) ?? ['raw' => $raw]];
}

function check(string $label, bool $ok, string $detail = ''): void
{
    global $passed, $failed;
    if ($ok) {
        $passed++;
        echo "PASS: $label\n";
    } else {
        $failed++;
        echo "FAIL: $label" . ($detail ? " — $detail" : '') . "\n";
    }
}

echo "=== Laravel 12 API Smoke Test ===\n";
echo "Base: $base\n\n";

$login = api('POST', "$base/users/login", ['job_number' => '001', 'password' => 'admin123']);
check('Login 001', ($login['body']['success'] ?? false) === true, $login['body']['message'] ?? 'no response');
$token = $login['body']['data']['token'] ?? '';
check('Token issued', $token !== '');

$me = api('GET', "$base/users/me", null, $token);
check('GET /users/me', ($me['body']['success'] ?? false) === true);

$buildings = api('GET', "$base/users/buildings", null, $token);
check('GET /users/buildings', ($buildings['body']['success'] ?? false) === true);

$policies = api('GET', "$base/users/refund-policies", null, $token);
check('GET /users/refund-policies', ($policies['body']['success'] ?? false) === true);

$reservations = api('GET', "$base/users/reservations", null, $token);
check('GET /users/reservations', ($reservations['body']['success'] ?? false) === true);

$dashboard = api('GET', "$base/users/dashboard/summary", null, $token);
check('GET /users/dashboard/summary', ($dashboard['body']['success'] ?? false) === true);

echo "\n=== Summary: $passed passed, $failed failed ===\n";
exit($failed > 0 ? 1 : 0);
