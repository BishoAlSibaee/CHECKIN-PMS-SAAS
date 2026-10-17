<?php

$base = $argv[1] ?? 'http://127.0.0.1:8001/api';

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
    ]);
    $raw = curl_exec($ch);
    curl_close($ch);
    return json_decode($raw, true) ?? ['raw' => $raw];
}

foreach (['002', '2'] as $job) {
    $login = api('POST', "$base/users/login", ['job_number' => $job, 'password' => 'admin123']);
    if (!($login['success'] ?? false)) {
        echo "Login failed for job_number=$job: " . ($login['message'] ?? 'unknown') . "\n";
        continue;
    }
    $data = $login['data'] ?? [];
    echo "Login OK job_number=$job\n";
    echo '  roles: ' . json_encode($data['roles'] ?? null, JSON_UNESCAPED_UNICODE) . "\n";
    echo '  permissions: ' . json_encode($data['permissions'] ?? null, JSON_UNESCAPED_UNICODE) . "\n";

    $token = $data['token'] ?? '';
    $me = api('GET', "$base/users/me", null, $token);
    echo '  me permissions: ' . json_encode($me['data']['permissions'] ?? null, JSON_UNESCAPED_UNICODE) . "\n";

    $buildings = api('GET', "$base/users/buildings", null, $token);
    echo '  GET buildings: ' . ($buildings['success'] ?? false ? 'OK' : ($buildings['message'] ?? 'FAIL')) . "\n";
    break;
}
