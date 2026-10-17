<?php

/**
 * Manual client-style test for mixed pricing via HTTP API.
 * Usage: php scripts/client_mixed_pricing_test.php [baseUrl]
 */

$baseUrl = $argv[1] ?? 'http://127.0.0.1:8001/api';

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
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return ['code' => $code, 'body' => json_decode($raw, true) ?? ['raw' => $raw]];
}

echo "=== Mixed pricing client test ===\n";
echo "API: {$baseUrl}\n\n";

$login = api('POST', "{$baseUrl}/users/login", [
    'job_number' => '001',
    'password' => 'admin123',
]);

if (($login['body']['success'] ?? false) !== true) {
    echo "FAIL: Login — " . json_encode($login['body'], JSON_UNESCAPED_UNICODE) . "\n";
    exit(1);
}
$token = $login['body']['data']['token'] ?? '';
echo "OK: Login\n";

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Pricingplan;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\RoomtypePricingplan;

$roomType = RoomType::create([
    'name_ar' => 'عميل ميكس',
    'name_en' => 'Client Mixed ' . uniqid(),
    'description' => 'Client test',
    'Min_daily_price' => 100,
    'Max_daily_price' => 200,
    'Min_monthly_price' => 2400,
    'Max_monthly_price' => 4800,
    'active_type' => 1,
]);

$long = Pricingplan::create([
    'NameAr' => 'طويلة',
    'NameEn' => 'Long ' . uniqid(),
    'StartDate' => '2026-01-01',
    'EndDate' => '2026-12-31',
    'ActiveType' => 1,
]);
RoomtypePricingplan::create([
    'roomtype_id' => $roomType->id,
    'pricingplan_id' => $long->id,
    'DailyPrice' => 280,
    'MonthlyPrice' => 6000,
]);

$promo = Pricingplan::create([
    'NameAr' => 'عرض 5 أغسطس',
    'NameEn' => 'Promo Aug5 ' . uniqid(),
    'StartDate' => '2026-08-05',
    'EndDate' => '2026-08-31',
    'ActiveType' => 1,
]);
RoomtypePricingplan::create([
    'roomtype_id' => $roomType->id,
    'pricingplan_id' => $promo->id,
    'DailyPrice' => 150,
    'MonthlyPrice' => 3500,
]);

$room = Room::where('active', 1)->first();
if (!$room) {
    echo "FAIL: No active room in database\n";
    exit(1);
}
$origType = $room->room_type_id;
$room->room_type_id = $roomType->id;
$room->save();

echo "OK: Test room type #{$roomType->id} (room #{$room->number}) promo Aug 5–31 + long base plan\n\n";

$price = api('POST', "{$baseUrl}/users/getRoomPrice", [
    'startDate' => '2026-08-01',
    'endDate' => '2026-08-11',
    'roomTypeId' => $roomType->id,
    'typeReservation' => 0,
    'price_calculation_mode' => 0,
], $token);

if (($price['body']['success'] ?? false) !== true) {
    echo "FAIL: getRoomPrice — " . json_encode($price['body'], JSON_UNESCAPED_UNICODE) . "\n";
    exit(1);
}

$days = $price['body']['data']['days'] ?? [];
$total = (float) ($price['body']['data']['totalPrice'] ?? 0);
$segments = $price['body']['data']['segments'] ?? [];

echo "Daily breakdown (mixed, Aug 1–11 checkout):\n";
ksort($days);
$pass = true;
foreach ($days as $date => $amount) {
    $expected = ($date < '2026-08-05') ? 100.0 : 150.0;
    $ok = abs((float) $amount - $expected) < 0.01;
    if (!$ok) {
        $pass = false;
    }
    $label = $date < '2026-08-05' ? 'room-type' : 'plan';
    echo sprintf(
        "  %s  %s  %6.2f  %s  %s\n",
        $ok ? 'OK' : 'FAIL',
        $date,
        (float) $amount,
        $label,
        $ok ? '' : "(expected {$expected})"
    );
}

echo "\nTotal: {$total} " . (abs($total - 1300) < 0.02 ? 'OK' : 'FAIL (expected 1300)') . "\n";
if (abs($total - 1300) >= 0.02) {
    $pass = false;
}

echo "\nSegments:\n";
foreach ($segments as $seg) {
    echo sprintf(
        "  %s → %s  %s  %s  %.2f (%d nights)\n",
        $seg['from'] ?? '?',
        $seg['to'] ?? '?',
        $seg['kind'] ?? '?',
        $seg['billing_source'] ?? '?',
        (float) ($seg['amount'] ?? 0),
        (int) ($seg['nights'] ?? 0)
    );
}

$hasPlan = false;
$hasRoom = false;
foreach ($segments as $seg) {
    if (($seg['billing_source'] ?? '') === 'plan') {
        $hasPlan = true;
    }
    if (in_array($seg['billing_source'] ?? '', ['min', 'max'], true)) {
        $hasRoom = true;
    }
}

if (!$hasPlan || !$hasRoom) {
    echo "\nFAIL: Expected both plan and room-type segments\n";
    $pass = false;
}

echo $pass ? "\n=== ALL CLIENT CHECKS PASSED ===\n" : "\n=== SOME CHECKS FAILED ===\n";

$room->room_type_id = $origType;
$room->save();

exit($pass ? 0 : 1);
