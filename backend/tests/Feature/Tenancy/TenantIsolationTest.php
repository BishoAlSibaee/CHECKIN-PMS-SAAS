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
 * Regression coverage for the Tenant layer added above Hotel — a user in one
 * tenant's hotel must never see, list, or modify another tenant's hotels or
 * users, even with a permission ('manage hotels'/'view users') that would
 * normally grant that access within their own tenant.
 */
class TenantIsolationTest extends TestCase
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
            'name' => 'Tenant Test '.$suffix,
            'job_number' => 'TENANT-TEST-'.$suffix,
            'email' => 'tenant-test-'.$suffix.'@test.local',
            'mobile' => '05'.$suffix,
            'active' => 1,
            'password' => bcrypt('tenant-test-password'),
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

    public function test_hotels_index_only_shows_the_callers_own_tenant(): void
    {
        $hotelA = $this->createHotelForNewTenant('A'.uniqid());
        $hotelB = $this->createHotelForNewTenant('B'.uniqid());
        $userA = $this->userForHotel($hotelA, ['manage hotels']);

        $response = $this->actingAs($userA, 'api')
            ->withHeader('X-Hotel-Id', (string) $hotelA->id)
            ->getJson('/api/users/getHotels');

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($hotelA->id));
        $this->assertFalse($ids->contains($hotelB->id));
    }

    public function test_cannot_update_another_tenants_hotel(): void
    {
        $hotelA = $this->createHotelForNewTenant('A'.uniqid());
        $hotelB = $this->createHotelForNewTenant('B'.uniqid());
        $userA = $this->userForHotel($hotelA, ['manage hotels']);

        $response = $this->actingAs($userA, 'api')
            ->withHeader('X-Hotel-Id', (string) $hotelA->id)
            ->postJson("/api/users/updateHotel/{$hotelB->id}", ['name_en' => 'Hijacked']);

        $response->assertStatus(404);
        $this->assertDatabaseMissing('hotels', ['id' => $hotelB->id, 'name_en' => 'Hijacked']);
    }

    public function test_assign_user_rejects_a_user_from_another_tenant(): void
    {
        $hotelA = $this->createHotelForNewTenant('A'.uniqid());
        $hotelB = $this->createHotelForNewTenant('B'.uniqid());
        $userA = $this->userForHotel($hotelA, ['manage hotels']);
        $userB = $this->userForHotel($hotelB, []);

        $response = $this->actingAs($userA, 'api')
            ->withHeader('X-Hotel-Id', (string) $hotelA->id)
            ->postJson("/api/users/hotels/{$hotelA->id}/users", ['user_id' => $userB->id]);

        $response->assertStatus(422);
        $this->assertDatabaseMissing('hotel_user', ['hotel_id' => $hotelA->id, 'user_id' => $userB->id]);
    }

    public function test_assign_user_allows_a_brand_new_user_with_no_hotel_yet(): void
    {
        $hotelA = $this->createHotelForNewTenant('A'.uniqid());
        $userA = $this->userForHotel($hotelA, ['manage hotels']);

        $suffix = (string) random_int(10000000, 99999999);
        $newUser = User::create([
            'name' => 'Brand New '.$suffix,
            'job_number' => 'NEW-USER-'.$suffix,
            'email' => 'new-user-'.$suffix.'@test.local',
            'mobile' => '05'.$suffix,
            'active' => 1,
            'password' => bcrypt('x'),
        ]);

        $response = $this->actingAs($userA, 'api')
            ->withHeader('X-Hotel-Id', (string) $hotelA->id)
            ->postJson("/api/users/hotels/{$hotelA->id}/users", ['user_id' => $newUser->id]);

        $response->assertOk();
        $this->assertDatabaseHas('hotel_user', ['hotel_id' => $hotelA->id, 'user_id' => $newUser->id]);
    }

    public function test_hotel_users_list_never_offers_another_tenants_users(): void
    {
        $hotelA = $this->createHotelForNewTenant('A'.uniqid());
        $hotelB = $this->createHotelForNewTenant('B'.uniqid());
        $userA = $this->userForHotel($hotelA, ['manage hotels']);
        $userB = $this->userForHotel($hotelB, []);

        $response = $this->actingAs($userA, 'api')
            ->withHeader('X-Hotel-Id', (string) $hotelA->id)
            ->getJson("/api/users/hotels/{$hotelA->id}/users");

        $response->assertOk();
        $availableIds = collect($response->json('data.available'))->pluck('id');
        $this->assertFalse($availableIds->contains($userB->id));
    }

    public function test_getinfousers_only_lists_the_callers_own_tenant(): void
    {
        $hotelA = $this->createHotelForNewTenant('A'.uniqid());
        $hotelB = $this->createHotelForNewTenant('B'.uniqid());
        $userA = $this->userForHotel($hotelA, ['manage users']);
        $userB = $this->userForHotel($hotelB, []);

        $response = $this->actingAs($userA, 'api')
            ->withHeader('X-Hotel-Id', (string) $hotelA->id)
            ->getJson('/api/users/getInfoUsers?per_page=500');

        $response->assertOk();
        $ids = collect($response->json('data.users'))->pluck('id');
        $this->assertTrue($ids->contains($userA->id));
        $this->assertFalse($ids->contains($userB->id));
    }
}
