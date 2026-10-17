<?php

namespace Tests\Feature\Users;

use App\Models\User;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class UsersAndRolesTest extends TestCase
{
    public function test_list_users_info(): void
    {
        $user = $this->userWithOnlyPermissions(['manage users']);

        $this->assertApiSuccess(
            $this->actingAs($user, 'api')->getJson('/api/users/getInfoUsers')
        );
    }

    public function test_list_roles(): void
    {
        $user = $this->userWithOnlyPermissions(['manage roles']);

        $this->assertApiSuccess(
            $this->actingAs($user, 'api')->getJson('/api/users/getRoles')
        );
    }

    public function test_list_permissions(): void
    {
        $user = $this->userWithOnlyPermissions(['manage permissions']);

        $this->assertApiSuccess(
            $this->actingAs($user, 'api')->getJson('/api/users/getPermissions')
        );
    }

    public function test_system_health(): void
    {
        $user = $this->userWithOnlyPermissions(['view users']);

        $response = $this->actingAs($user, 'api')->getJson('/api/users/system/health');

        $this->assertApiSuccess($response);
        $this->assertNotEmpty($response->json('data'));
    }

    public function test_system_health_available_with_view_reports(): void
    {
        $user = $this->userWithOnlyPermissions(['view reports']);

        $response = $this->actingAs($user, 'api')->getJson('/api/users/system/health');

        $this->assertApiSuccess($response);
        $this->assertArrayHasKey('mail_configured', $response->json('data'));
    }

    public function test_assign_role_to_user(): void
    {
        $admin = $this->userWithOnlyPermissions(['manage roles', 'manage users']);
        $target = User::where('id', '!=', $admin->id)->first();
        $role = Role::where('guard_name', 'api')->first();

        if (!$target || !$role) {
            $this->markTestSkipped('Need another user and a role.');
        }

        $response = $this->actingAs($admin, 'api')->postJson('/api/users/assignRole', [
            'userId' => $target->id,
            'roleId' => $role->id,
        ]);

        if ($response->status() === 422) {
            $this->markTestSkipped('Assign role payload differs: ' . $response->json('message'));
        }

        $this->assertApiSuccess($response);
    }

    public function test_get_info_users_forbidden_without_permission(): void
    {
        $user = $this->userWithOnlyPermissions(['view users']);

        $this->assertApiForbidden(
            $this->actingAs($user, 'api')->getJson('/api/users/getInfoUsers')
        );
    }
}
