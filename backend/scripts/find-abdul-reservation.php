<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Reservation;

$expire = $argv[1] ?? '2026-06-18';

$rows = Reservation::with('client')
    ->where('expire_date', $expire)
    ->orderByDesc('id')
    ->get(['id', 'start_date', 'expire_date', 'client_id']);

echo "expire_date={$expire}: " . $rows->count() . " reservation(s)\n";
foreach ($rows as $r) {
    $c = $r->client;
    $name = trim(($c->first_name ?? '') . ' ' . ($c->last_name ?? ''));
    echo "  #{$r->id} {$name} {$r->start_date} -> {$r->expire_date}\n";
}

$rows2 = Reservation::with('client')
    ->whereHas('client', function ($q) {
        $q->where('first_name', 'like', '%عبد%')
            ->orWhere('first_name', 'like', '%Abdul%')
            ->orWhere('last_name', 'like', '%رووف%')
            ->orWhere('last_name', 'like', '%Raouf%');
    })
    ->orderByDesc('id')
    ->limit(10)
    ->get(['id', 'start_date', 'expire_date', 'client_id']);

echo "\nName search:\n";
foreach ($rows2 as $r) {
    $c = $r->client;
    $name = trim(($c->first_name ?? '') . ' ' . ($c->last_name ?? ''));
    echo "  #{$r->id} {$name} {$r->start_date} -> {$r->expire_date}\n";
}

