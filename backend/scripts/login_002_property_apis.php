<?php

$base = $argv[1] ?? 'http://127.0.0.1:8001/api';
$ch = curl_init("$base/users/login");
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_POST=>true, CURLOPT_HTTPHEADER=>['Content-Type: application/json'], CURLOPT_POSTFIELDS=>json_encode(['job_number'=>'002','password'=>'admin123'])]);
$login = json_decode(curl_exec($ch), true);
curl_close($ch);
$token = $login['data']['token'] ?? '';
echo 'permissions: ' . json_encode($login['data']['permissions'] ?? [], JSON_UNESCAPED_UNICODE) . "\n";
foreach (['buildings','rooms','getRoomType'] as $ep) {
  $ch = curl_init("$base/users/$ep");
  curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$token,'Accept: application/json']]);
  $body = json_decode(curl_exec($ch), true);
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
  echo "$ep HTTP $code " . (($body['success'] ?? false) ? 'OK' : ($body['message'] ?? 'FAIL')) . "\n";
}
