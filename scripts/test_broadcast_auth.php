<?php

$base = $argv[1] ?? 'http://127.0.0.1:8001/api';

$loginCtx = stream_context_create([
    'http' => [
        'method' => 'POST',
        'header' => "Content-Type: application/json\r\nAccept: application/json\r\n",
        'content' => json_encode(['job_number' => '001', 'password' => 'admin123']),
        'ignore_errors' => true,
    ],
]);

$loginRaw = file_get_contents(rtrim($base, '/') . '/users/login', false, $loginCtx);
$login = json_decode($loginRaw ?: '');

if (!$login || !($login->success ?? false)) {
    fwrite(STDERR, "LOGIN FAIL: {$loginRaw}\n");
    exit(1);
}

$token = $login->data->token ?? '';
echo 'Token prefix: ' . substr($token, 0, 24) . "...\n";
echo 'Roles: ' . json_encode($login->data->roles ?? []) . "\n";
echo 'Permissions count: ' . count((array) ($login->data->permissions ?? [])) . "\n";
echo 'Has view reservations: ' . (in_array('view reservations', (array) ($login->data->permissions ?? []), true) ? 'yes' : 'no') . "\n";

$authCtx = stream_context_create([
    'http' => [
        'method' => 'POST',
        'header' => "Content-Type: application/x-www-form-urlencoded\r\nAccept: application/json\r\nAuthorization: Bearer {$token}\r\n",
        'content' => 'channel_name=private-hotel.operations&socket_id=1234.5678',
        'ignore_errors' => true,
    ],
]);

$authRaw = file_get_contents(rtrim(str_replace('/api', '', $base), '/') . '/api/broadcasting/auth', false, $authCtx);
echo "Auth HTTP headers:\n";
foreach ($http_response_header ?? [] as $h) {
    echo "  {$h}\n";
}
echo "Auth body: {$authRaw}\n";
