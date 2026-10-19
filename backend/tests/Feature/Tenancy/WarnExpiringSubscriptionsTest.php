<?php

namespace Tests\Feature\Tenancy;

use App\Models\AppNotification;
use App\Models\Hotel;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Daily heads-up before Tenant::isAccessAllowed() starts locking the tenant
 * out — see WarnExpiringSubscriptionsCommand's own docblock for why this
 * exists (staff can't renew it themselves, only a platform operator can).
 */
class WarnExpiringSubscriptionsTest extends TestCase
{
    use DatabaseTransactions;

    protected function connectionsToTransact(): array
    {
        return ['mysql', 'mysql2'];
    }

    private function createTenantHotel(string $label, ?\Carbon\Carbon $subscriptionEndsAt, bool $isActive = true): Hotel
    {
        $tenant = Tenant::create([
            'name_ar' => 'مستأجر '.$label,
            'name_en' => 'Tenant '.$label,
            'is_active' => $isActive,
            'subscription_ends_at' => $subscriptionEndsAt,
        ]);

        return Hotel::create([
            'tenant_id' => $tenant->id,
            'name_ar' => 'فندق '.$label,
            'name_en' => 'Hotel '.$label,
            'is_active' => true,
        ]);
    }

    public function test_it_warns_a_tenant_expiring_within_the_window(): void
    {
        $hotel = $this->createTenantHotel('Expiring'.uniqid(), now()->addDays(10));

        $this->artisan('subscriptions:warn-expiring')->assertSuccessful();

        $this->assertDatabaseHas('app_notifications', [
            'hotel_id' => $hotel->id,
            'type' => 'subscription_expiring_soon',
        ]);
    }

    public function test_it_does_not_warn_a_tenant_outside_the_window(): void
    {
        $hotel = $this->createTenantHotel('FarOut'.uniqid(), now()->addDays(90));

        $this->artisan('subscriptions:warn-expiring')->assertSuccessful();

        $this->assertDatabaseMissing('app_notifications', [
            'hotel_id' => $hotel->id,
            'type' => 'subscription_expiring_soon',
        ]);
    }

    public function test_it_does_not_warn_a_tenant_with_no_expiry(): void
    {
        $hotel = $this->createTenantHotel('NoExpiry'.uniqid(), null);

        $this->artisan('subscriptions:warn-expiring')->assertSuccessful();

        $this->assertDatabaseMissing('app_notifications', [
            'hotel_id' => $hotel->id,
            'type' => 'subscription_expiring_soon',
        ]);
    }

    public function test_it_does_not_warn_twice_for_the_same_expiry_date(): void
    {
        $hotel = $this->createTenantHotel('Repeat'.uniqid(), now()->addDays(10));

        $this->artisan('subscriptions:warn-expiring')->assertSuccessful();
        $this->artisan('subscriptions:warn-expiring')->assertSuccessful();

        $this->assertSame(1, AppNotification::where('hotel_id', $hotel->id)
            ->where('type', 'subscription_expiring_soon')
            ->count());
    }

    public function test_renewing_lets_a_fresh_warning_fire_for_the_new_date(): void
    {
        $hotel = $this->createTenantHotel('Renewed'.uniqid(), now()->addDays(10));

        $this->artisan('subscriptions:warn-expiring')->assertSuccessful();

        // Renew far out, then bring it back into the window under a new end date —
        // the old notification's params won't match, so a new one is allowed.
        $hotel->tenant->update(['subscription_ends_at' => now()->addDays(29)]);
        $this->artisan('subscriptions:warn-expiring')->assertSuccessful();

        $this->assertSame(2, AppNotification::where('hotel_id', $hotel->id)
            ->where('type', 'subscription_expiring_soon')
            ->count());
    }
}
