<?php
$c = file_get_contents(__DIR__ . '/../vendor/laravel/framework/src/Illuminate/Broadcasting/Broadcasters/PusherBroadcaster.php');
$start = strpos($c, 'public function auth');
echo substr($c, $start, 900);
echo "\n---\n";
$c2 = file_get_contents(__DIR__ . '/../vendor/laravel/framework/src/Illuminate/Broadcasting/Broadcasters/Broadcaster.php');
$start2 = strpos($c2, 'protected function verifyUserCanAccessChannel');
echo substr($c2, $start2, 1500);
