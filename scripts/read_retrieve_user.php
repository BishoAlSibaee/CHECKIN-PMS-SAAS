<?php
$c = file_get_contents(__DIR__ . '/../vendor/laravel/framework/src/Illuminate/Broadcasting/Broadcasters/Broadcaster.php');
$start = strpos($c, 'protected function retrieveUser');
echo substr($c, $start, 1200);
