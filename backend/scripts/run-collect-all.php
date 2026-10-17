<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\User;
use App\Services\CollectionsService;
use App\Services\ReservationFinancialService;
use Carbon\Carbon;

$user = User::first();
$before = app(CollectionsService::class)->summarize(Carbon::today());
echo 'Before: count=' . $before['count'] . ' total=' . $before['total_balance'] . "\n";

$result = app(ReservationFinancialService::class)->collectAllOutstanding((int) $user->id);
echo 'Collected: ' . json_encode($result) . "\n";

$after = app(CollectionsService::class)->summarize(Carbon::today());
echo 'After: count=' . $after['count'] . ' total=' . $after['total_balance'] . "\n";
