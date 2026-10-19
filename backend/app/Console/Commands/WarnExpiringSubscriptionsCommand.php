<?php

namespace App\Console\Commands;

use App\Models\AppNotification;
use App\Models\Tenant;
use App\Services\NotificationService;
use Illuminate\Console\Command;

/**
 * Daily heads-up so a tenant isn't surprised by the hard lockout
 * Tenant::isAccessAllowed() enforces at login/every request once their
 * subscription actually lapses (see UsersController::login,
 * ResolveCurrentHotel, AuthenticateKioskDevice). Purely informational —
 * staff can't renew it themselves in-app, only a platform operator can
 * (see TenantsController::renewSubscription) — so this just tells them to
 * reach out before they get locked out.
 *
 * At most one notification per hotel per subscription_ends_at value: a
 * renewal changes that value, which naturally lets a fresh warning fire
 * next time the (new) date comes within range — no extra "already warned"
 * column needed.
 */
class WarnExpiringSubscriptionsCommand extends Command
{
    protected $signature = 'subscriptions:warn-expiring {--days=30 : Warn once the subscription end date is within this many days}';

    protected $description = "Notify each expiring tenant's hotel admins before their subscription lapses";

    public function handle(NotificationService $notifications): int
    {
        $windowDays = (int) $this->option('days');
        $warned = 0;

        $expiringTenants = Tenant::query()
            ->where('is_active', true)
            ->whereNotNull('subscription_ends_at')
            ->whereBetween('subscription_ends_at', [now(), now()->addDays($windowDays)])
            ->with('hotels:id,tenant_id')
            ->get();

        foreach ($expiringTenants as $tenant) {
            $endsAtIso = $tenant->subscription_ends_at->toIso8601String();
            $daysLeft = (int) now()->diffInDays($tenant->subscription_ends_at, false);

            foreach ($tenant->hotels as $hotel) {
                $alreadyWarnedThisCycle = AppNotification::where('hotel_id', $hotel->id)
                    ->where('type', 'subscription_expiring_soon')
                    ->where('params->subscription_ends_at', $endsAtIso)
                    ->exists();

                if ($alreadyWarnedThisCycle) {
                    continue;
                }

                $notifications->notify(
                    (int) $hotel->id,
                    'subscription_expiring_soon',
                    'NOTIFICATIONS.SUBSCRIPTION_EXPIRING_SOON_TITLE',
                    [
                        'days' => $daysLeft,
                        'date' => $tenant->subscription_ends_at->toDateString(),
                        'subscription_ends_at' => $endsAtIso,
                    ],
                    null,
                    'manage hotels'
                );
                $warned++;
            }
        }

        $this->info("Checked {$expiringTenants->count()} expiring tenant(s); sent {$warned} notification(s).");

        return self::SUCCESS;
    }
}
