<?php

$ctx = stream_context_create([
    'http' => [
        'method' => 'POST',
        'header' => "Content-Type: application/x-www-form-urlencoded\r\nAccept: application/json\r\n",
        'content' => 'channel_name=private-hotel.operations&socket_id=1234.5678',
        'ignore_errors' => true,
    ],
]);

$body = file_get_contents('http://127.0.0.1:8001/api/broadcasting/auth', false, $ctx);
echo "Without token:\n";
foreach ($http_response_header ?? [] as $h) {
    echo "  {$h}\n";
}
echo $body . "\n";
