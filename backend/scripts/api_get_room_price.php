<?php

$baseUrl = $argv[1] ?? 'http://127.0.0.1:8001/api';
$roomTypeId = (int) ($argv[2] ?? 1);

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

$login = api('POST', "{$baseUrl}/users/login", ['job_number' => '001', 'password' => 'admin123']);
$token = $login['data']['token'] ?? '';
if (!$token) {
    echo "Login failed\n";
    exit(1);
}

$res = api('POST', "{$baseUrl}/users/getRoomPrice", [
    'startDate' => '2026-08-01',
    'endDate' => '2026-08-11',
    'roomTypeId' => $roomTypeId,
    'typeReservation' => 0,
    'price_calculation_mode' => 0,
], $token);

echo json_encode($res, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
