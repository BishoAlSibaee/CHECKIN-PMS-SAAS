<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\User;
use App\Services\RevenueAccrualService;
use Carbon\Carbon;

$user = User::first();
if (!$user) {
    echo "No user\n";
    exit(1);
}

$tokenResult = $user->createToken('audit');
$token = $tokenResult->accessToken ?? $tokenResult->token ?? (method_exists($tokenResult, 'getToken') ? $tokenResult->getToken() : null);
if (!$token) {
    echo "Could not obtain access token\n";
    exit(1);
}
echo "Token created for user {$user->email}\n";

$revenue = app(RevenueAccrualService::class);
$aug = $revenue->calculate('total', null, Carbon::parse('2026-08-01'), Carbon::parse('2026-08-31'), true);
echo "Service Aug: total={$aug['current']['total']} count={$aug['current']['count']}\n";

// Simulate HTTP via internal request
$request = \Illuminate\Http\Request::create(
    '/api/users/revenue/total',
    'GET',
    ['start_date' => '2026-08-01', 'end_date' => '2026-08-31', 'include_details' => '1']
);
$request->headers->set('Authorization', 'Bearer ' . $token);
$response = $app->handle($request);
echo "HTTP revenue status: " . $response->getStatusCode() . "\n";
$json = json_decode($response->getContent(), true);
echo "HTTP Aug total: " . ($json['data']['revenue']['current']['total'] ?? 'N/A') . "\n";
