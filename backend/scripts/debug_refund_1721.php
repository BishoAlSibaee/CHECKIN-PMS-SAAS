<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\RefundPolicy;
use App\Models\Reservation;
use App\Services\RefundPolicyService;
use Carbon\Carbon;

$id = (int) ($argv[1] ?? 1721);
$reservation = Reservation::with('payments')->find($id);

if (!$reservation) {
    echo "Reservation {$id} not found\n";
    exit(1);
}

$service = app(RefundPolicyService::class);
$context = $service->buildContext($reservation);

echo "=== Reservation {$id} ===\n";
echo json_encode([
    'status' => $reservation->reservation_status,
    'rent_type' => $reservation->rent_type,
    'start_date' => $reservation->start_date,
    'expire_date' => $reservation->expire_date,
    'total' => $reservation->total,
    'logedin' => $reservation->logedin,
    'net_paid' => $service->netPaid($reservation),
    'today' => Carbon::today()->toDateString(),
], JSON_PRETTY_PRINT) . "\n\n";

echo "=== Context ===\n";
echo json_encode($context, JSON_PRETTY_PRINT) . "\n\n";

echo "=== Policies (payment_status match) ===\n";
$candidates = RefundPolicy::query()
    ->where('payment_status', $context['payment_status'])
    ->where(function ($q) use ($reservation) {
        $q->whereNull('rent_type')
            ->orWhere('rent_type', (int) $reservation->rent_type);
    })
    ->get();

foreach ($candidates as $policy) {
    $timing = $policy->timing ?? ((int) $policy->during_stay === 1 ? 'after_start' : 'before_start');
    $threshold = $policy->days_threshold ?? $policy->days_before_checkin;
    $matches = false;
    if ($timing === 'before_start') {
        $matches = !$context['during_stay'] && $context['days_until_start'] >= $threshold;
    } else {
        $matches = $context['during_stay'] && $context['days_since_start'] >= $threshold;
    }
    echo sprintf(
        "id=%d name=%s timing=%s threshold=%d matches=%s\n",
        $policy->id,
        $policy->name,
        $timing,
        $threshold,
        $matches ? 'YES' : 'NO'
    );
}

echo "\n=== Resolved policy ===\n";
$policy = $service->resolvePolicy($reservation, $context);
echo $policy ? "id={$policy->id} {$policy->name}\n" : "NONE\n";

try {
    $preview = $service->preview($reservation);
    echo "\n=== Preview OK ===\n";
    echo json_encode($preview, JSON_PRETTY_PRINT) . "\n";
} catch (\Throwable $e) {
    echo "\n=== Preview FAILED ===\n";
    echo $e->getMessage() . "\n";
}
