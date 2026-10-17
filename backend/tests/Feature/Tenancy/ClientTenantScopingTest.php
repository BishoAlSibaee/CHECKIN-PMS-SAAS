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
 * Client (guest) records are shared across hotels of the SAME tenant (by
 * design — one business's own branches should recognize a returning guest),
 * but must never leak across DIFFERENT tenants. Companion to
 * TenantIsolationTest, which covers the Hotel/User side of the same layer.
 */
class ClientTenantScopingTest extends TestCase
{
    use DatabaseTransactions;

    protected function connectionsToTransact(): array
    {
        return ['mysql', 'mysql2'];
    }

    private function createHotelForNewTenant(string $label): Hotel
    {
        $tenant = Tenant::create([
            'name_ar' => 'مستأجر '.$label,
            'name_en' => 'Tenant '.$label,
            'is_active' => true,
        ]);

        return Hotel::create([
            'tenant_id' => $tenant->id,
            'name_ar' => 'فندق '.$label,
            'name_en' => 'Hotel '.$label,
            'is_active' => true,
        ]);
    }

    /** @param  list<string>  $permissions */
    private function userForHotel(Hotel $hotel, array $permissions): User
    {
        $suffix = (string) random_int(10000000, 99999999);
        $user = User::create([
            'name' => 'Client Scope Test '.$suffix,
            'job_number' => 'CLIENT-SCOPE-'.$suffix,
            'email' => 'client-scope-'.$suffix.'@test.local',
            'mobile' => '05'.$suffix,
            'active' => 1,
            'password' => bcrypt('x'),
        ]);

        $user->hotels()->attach($hotel->id);

        $registrar = app(PermissionRegistrar::class);
        $registrar->setPermissionsTeamId($hotel->id);
        foreach ($permissions as $name) {
            Permission::findOrCreate($name, 'api');
        }
        $user->syncRoles([]);
        $user->syncPermissions($permissions);
        $registrar->forgetCachedPermissions();

        return $user;
    }

    public function test_same_mobile_number_is_allowed_across_different_tenants(): void
    {
        $hotelA = $this->createHotelForNewTenant('A'.uniqid());
        $hotelB = $this->createHotelForNewTenant('B'.uniqid());
        $userA = $this->userForHotel($hotelA, ['manage clients']);
        $userB = $this->userForHotel($hotelB, ['manage clients']);

        $mobile = '05'.random_int(10000000, 99999999);

        $responseA = $this->actingAs($userA, 'api')
            ->withHeader('X-Hotel-Id', (string) $hotelA->id)
            ->postJson('/api/users/addClient', [
                'first_name' => 'Guest',
                'last_name' => 'OfTenantA',
                'international_code' => '+966',
                'IdType' => 'ID',
                'IdNumber' => 'TEST-'.uniqid(),
                'gender' => 'MALE',
                'guest_type' => 'CITIZEN',
                'mobile' => $mobile,
            ]);
        $this->assertApiSuccess($responseA);

        // Before the Tenant layer this failed with a global unique
        // constraint violation — the whole point of this test.
        $responseB = $this->actingAs($userB, 'api')
            ->withHeader('X-Hotel-Id', (string) $hotelB->id)
            ->postJson('/api/users/addClient', [
                'first_name' => 'Guest',
                'last_name' => 'OfTenantB',
                'international_code' => '+966',
                'IdType' => 'ID',
                'IdNumber' => 'TEST-'.uniqid(),
                'gender' => 'MALE',
                'guest_type' => 'CITIZEN',
                'mobile' => $mobile,
            ]);
        $this->assertApiSuccess($responseB);

        $this->assertNotEquals($responseA->json('data.id'), $responseB->json('data.id'));
    }

    public function test_client_list_does_not_leak_across_tenants(): void
    {
        $hotelA = $this->createHotelForNewTenant('A'.uniqid());
        $hotelB = $this->createHotelForNewTenant('B'.uniqid());
        $userA = $this->userForHotel($hotelA, ['manage clients', 'view clients']);
        $userB = $this->userForHotel($hotelB, ['manage clients', 'view clients']);

        $uniqueLastName = 'OnlyInTenantA'.uniqid();

        $createResponse = $this->actingAs($userA, 'api')
            ->withHeader('X-Hotel-Id', (string) $hotelA->id)
            ->postJson('/api/users/addClient', [
                'first_name' => 'Guest',
                'last_name' => $uniqueLastName,
                'international_code' => '+966',
                'IdType' => 'ID',
                'IdNumber' => 'TEST-'.uniqid(),
                'gender' => 'MALE',
                'guest_type' => 'CITIZEN',
                'mobile' => '05'.random_int(10000000, 99999999),
            ]);
        $createResponse->assertOk();

        $responseB = $this->actingAs($userB, 'api')
            ->withHeader('X-Hotel-Id', (string) $hotelB->id)
            ->getJson('/api/users/getClient?search='.$uniqueLastName);

        $responseB->assertOk();
        $this->assertSame(0, count($responseB->json('data')));
    }
}
