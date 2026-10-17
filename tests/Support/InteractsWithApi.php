<?php

namespace Tests\Support;

use App\Models\User;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Permission;

trait InteractsWithApi
{
    /** @return list<string> */
    protected function allAdminPermissions(): array
    {
        return [
            'view reports', 'view financial reports', 'view accounting reports', 'export reports',
            'manage journal entries', 'close accounting period', 'manage chart of accounts',
            'view users', 'manage users', 'manage roles', 'manage permissions',
            'view buildings', 'manage buildings', 'view floors', 'manage floors',
            'view suites', 'manage suites', 'view rooms', 'manage rooms',
            'view room types', 'manage room types', 'manage pricing plans',
            'manage facilities', 'manage features', 'manage stay reasons',
            'manage discounts', 'manage taxes', 'manage job titles', 'manage departments',
            'manage penalties', 'manage reservation sources', 'manage clients', 'manage client notes',
            'manage guest classifications', 'manage peak days', 'manage peak months', 'manage refund policies',
            'view reservations', 'create reservations', 'update reservations', 'cancel reservations',
            'view earnings', 'view revenue', 'view payments', 'manage refunds',
            'view clients',
        ];
    }

    protected function adminUser(): User
    {
        return $this->userWithApiPermissions($this->allAdminPermissions());
    }

    protected function userWithOnlyPermissions(array $permissions): User
    {
        $user = $this->apiTestUser();

        foreach ($permissions as $name) {
            Permission::findOrCreate($name, 'api');
        }

        // syncRoles/syncPermissions stamp the pivot's team_foreign_key
        // (hotel_id) from whatever team id is currently set — outside a
        // request there is none, so set it to the user's hotel first.
        $defaultHotelId = \App\Models\Hotel::query()->orderBy('id')->value('id');
        app()[\Spatie\Permission\PermissionRegistrar::class]->setPermissionsTeamId($defaultHotelId);
        $user->syncRoles([]);
        $user->syncPermissions($permissions);
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        return $user;
    }

    protected function resolveSmokeQuery(array $query): array
    {
        if (array_key_exists('client_id', $query)) {
            $client = \App\Models\Client::query()->first();
            if ($client) {
                $query['client_id'] = $client->id;
            }
        }

        return $query;
    }

    protected function assertApiSuccess(TestResponse $response, int $status = 200): void
    {
        $response->assertStatus($status);
        $response->assertJsonPath('success', true);
        $response->assertJsonStructure(['success', 'message']);
    }

    protected function assertApiValidationError(TestResponse $response): void
    {
        $response->assertStatus(422);
        $response->assertJsonPath('success', false);
        $response->assertJsonPath('code', 422);
        $this->assertNotEmpty($response->json('message'));
    }

    protected function assertApiForbidden(TestResponse $response): void
    {
        $response->assertStatus(403);
    }

    protected function assertApiUnauthorized(TestResponse $response): void
    {
        $response->assertStatus(401);
    }

    protected function assertPaginationShape(TestResponse $response): void
    {
        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonStructure([
            'success', 'message', 'per_page', 'total', 'current_page', 'last_page', 'data',
        ]);
    }

    protected function uniqueSuffix(): string
    {
        return (string) (microtime(true) * 10000);
    }
}
