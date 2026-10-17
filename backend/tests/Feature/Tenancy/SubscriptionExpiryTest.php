<?php

namespace Tests\Feature\Tenancy;

use App\Models\Hotel;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * A trial/subscription past its end date must lock the customer out
 * entirely — not just degrade gracefully. Covers both enforcement points:
 * UsersController::login() (no token issued at all) and ResolveCurrentHotel
 * (a session already in progress when the clock runs out).
 */
class SubscriptionExpiryTest extends TestCase
{
    use DatabaseTransactions;

    protected function connectionsToTransact(): array
    {
        return ['mysql', 'mysql2'];
    }

    private function createHotelForNewTenant(string $label, ?\Carbon\Carbon $subscriptionEndsAt = null): Hotel
    {
        $tenant = Tenant::create([
            'name_ar' => 'مستأجر '.$label,
            'name_en' => 'Tenant '.$label,
            'is_active' => true,
            'subscription_ends_at' => $subscriptionEndsAt,
        ]);

        return Hotel::create([
            'tenant_id' => $tenant->id,
            'name_ar' => 'فندق '.$label,
            'name_en' => 'Hotel '.$label,
            'is_active' => true,
        ]);
    }

    private function userForHotel(Hotel $hotel, string $password = 'expiry-test-password'): User
    {
        $suffix = (string) random_int(10000000, 99999999);
        $user = User::create([
            'name' => 'Expiry Test '.$suffix,
            'job_number' => 'EXPIRY-TEST-'.$suffix,
            'email' => 'expiry-test-'.$suffix.'@test.local',
            'mobile' => '05'.$suffix,
            'active' => 1,
            'password' => bcrypt($password),
        ]);

        $user->hotels()->attach($hotel->id);

        $registrar = app(PermissionRegistrar::class);
        $registrar->setPermissionsTeamId($hotel->id);
        Permission::findOrCreate('view reservations', 'api');
        $user->syncRoles([]);
        $user->syncPermissions(['view reservations']);
        $registrar->forgetCachedPermissions();

        return $user;
    }

    public function test_login_is_rejected_once_subscription_has_expired(): void
    {
        $hotel = $this->createHotelForNewTenant('Expired'.uniqid(), now()->subDay());
        $user = $this->userForHotel($hotel, 'expiry-test-password');

        $response = $this->postJson('/api/users/login', [
            'job_number' => $user->job_number,
            'password' => 'expiry-test-password',
        ]);

        $response->assertStatus(403);
        $this->assertStringContainsString('expired', (string) $response->json('message'));
    }

    public function test_login_succeeds_when_subscription_has_no_expiry(): void
    {
        $hotel = $this->createHotelForNewTenant('NoExpiry'.uniqid(), null);
        $user = $this->userForHotel($hotel, 'expiry-test-password');

        $response = $this->postJson('/api/users/login', [
            'job_number' => $user->job_number,
            'password' => 'expiry-test-password',
        ]);

        $response->assertOk();
    }

    public function test_login_succeeds_while_subscription_still_active(): void
    {
        $hotel = $this->createHotelForNewTenant('Active'.uniqid(), now()->addDays(5));
        $user = $this->userForHotel($hotel, 'expiry-test-password');

        $response = $this->postJson('/api/users/login', [
            'job_number' => $user->job_number,
            'password' => 'expiry-test-password',
        ]);

        $response->assertOk();
    }

    public function test_an_in_progress_session_is_locked_out_the_moment_it_expires(): void
    {
        $hotel = $this->createHotelForNewTenant('WasActive'.uniqid(), now()->addMinute());
        $user = $this->userForHotel($hotel, 'expiry-test-password');

        $loginResponse = $this->postJson('/api/users/login', [
            'job_number' => $user->job_number,
            'password' => 'expiry-test-password',
        ]);
        $loginResponse->assertOk();
        $token = $loginResponse->json('data.token');
        $this->assertNotEmpty($token);

        // Expire it now, as if the clock just ran out mid-session.
        $hotel->tenant->update(['subscription_ends_at' => now()->subSecond()]);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->withHeader('X-Hotel-Id', (string) $hotel->id)
            ->getJson('/api/users/dashboard/summary');

        $response->assertStatus(403);
    }
}
