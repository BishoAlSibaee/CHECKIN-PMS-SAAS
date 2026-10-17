<?php

namespace Tests\Feature\Tenancy;

use App\Models\Hotel;
use App\Models\KioskDevice;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * A kiosk device has no login to gate at login time — AuthenticateKioskDevice
 * is its only enforcement point, mirroring what ResolveCurrentHotel does for
 * staff sessions. Without this, a deactivated or expired tenant's kiosk would
 * keep accepting check-ins/payments indefinitely even though staff can no
 * longer log in.
 */
class KioskSubscriptionGateTest extends TestCase
{
    use DatabaseTransactions;

    protected function connectionsToTransact(): array
    {
        return ['mysql', 'mysql2'];
    }

    private function createKioskDevice(?\Carbon\Carbon $subscriptionEndsAt, bool $tenantActive = true): array
    {
        $suffix = (string) random_int(10000000, 99999999);

        $tenant = Tenant::create([
            'name_ar' => 'مستأجر كيوسك '.$suffix,
            'name_en' => 'Kiosk Tenant '.$suffix,
            'is_active' => $tenantActive,
            'subscription_ends_at' => $subscriptionEndsAt,
        ]);

        $hotel = Hotel::create([
            'tenant_id' => $tenant->id,
            'name_ar' => 'فندق كيوسك '.$suffix,
            'name_en' => 'Kiosk Hotel '.$suffix,
            'is_active' => true,
        ]);

        $buildingId = \DB::table('buildings')->insertGetId([
            'hotel_id' => $hotel->id,
            'name' => 'Main',
            'number' => 'A'.$suffix,
            'active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $serviceUser = User::create([
            'name' => 'Kiosk Service '.$suffix,
            'job_number' => 'KIOSK-TEST-'.$suffix,
            'email' => 'kiosk-test-'.$suffix.'@test.local',
            'mobile' => '05'.$suffix,
            'active' => 1,
            'password' => bcrypt('x'),
        ]);
        $serviceUser->hotels()->attach($hotel->id);

        $registrar = app(PermissionRegistrar::class);
        $registrar->setPermissionsTeamId($hotel->id);
        Permission::findOrCreate('view reservations', 'api');
        $serviceUser->syncRoles([]);
        $serviceUser->syncPermissions(['view reservations']);
        $registrar->forgetCachedPermissions();

        $token = Str::random(40);
        KioskDevice::create([
            'name' => 'Test Kiosk '.$suffix,
            'building_id' => $buildingId,
            'user_id' => $serviceUser->id,
            'token_hash' => hash('sha256', $token),
            'active' => true,
        ]);

        return ['token' => $token, 'tenant' => $tenant];
    }

    public function test_kiosk_requests_are_rejected_once_the_subscription_has_expired(): void
    {
        ['token' => $token] = $this->createKioskDevice(now()->subDay());

        $response = $this->withHeader('Authorization', 'Bearer '.$token)->getJson('/api/kiosk/rooms');

        $response->assertStatus(403);
        $this->assertStringContainsString('expired', (string) $response->json('message'));
    }

    public function test_kiosk_requests_are_rejected_once_the_tenant_is_deactivated(): void
    {
        ['token' => $token] = $this->createKioskDevice(null, tenantActive: false);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)->getJson('/api/kiosk/rooms');

        $response->assertStatus(403);
        $this->assertStringContainsString('deactivated', (string) $response->json('message'));
    }

    public function test_kiosk_requests_are_not_blocked_while_subscription_is_active(): void
    {
        ['token' => $token] = $this->createKioskDevice(now()->addDays(5));

        $response = $this->withHeader('Authorization', 'Bearer '.$token)->getJson('/api/kiosk/rooms');

        // Whatever the room-availability endpoint itself returns (it needs
        // date params this test doesn't supply), it must not be blocked by
        // the tenant gate.
        $this->assertNotSame(403, $response->getStatusCode());
    }
}
