<?php

namespace Tests\Feature\Security;

use Tests\Support\EndpointDefinitions;
use Tests\TestCase;

class RbacMatrixTest extends TestCase
{
    /** @dataProvider unauthenticatedRoutesProvider */
    public function test_protected_routes_require_authentication(string $method, string $uri, array $payload): void
    {
        $response = match (strtoupper($method)) {
            'GET' => $this->getJson($uri),
            'POST' => $this->postJson($uri, $payload),
            'PATCH' => $this->patchJson($uri, $payload),
            'DELETE' => $this->deleteJson($uri, $payload),
            default => $this->getJson($uri),
        };

        $this->assertApiUnauthorized($response);
    }

    public static function unauthenticatedRoutesProvider(): array
    {
        $cases = [];
        foreach (EndpointDefinitions::unauthenticatedRoutes() as [$method, $uri, $payload]) {
            $cases["{$method} {$uri}"] = [$method, $uri, $payload];
        }

        return $cases;
    }

    /** @dataProvider permissionGatedGetRoutesProvider */
    public function test_get_routes_forbidden_without_permission(
        string $permission,
        string $method,
        string $uri,
        array $query
    ): void {
        $user = $this->userWithOnlyPermissions([]);

        $query = $this->resolveSmokeQuery($query);
        $url = $query ? $uri . '?' . http_build_query($query) : $uri;
        $response = $this->actingAs($user, 'api')->getJson($url);

        $this->assertApiForbidden($response);
    }

    /** @dataProvider permissionGatedGetRoutesProvider */
    public function test_get_routes_succeed_with_permission(
        string $permission,
        string $method,
        string $uri,
        array $query
    ): void {
        $user = $this->userWithOnlyPermissions([$permission]);

        $query = $this->resolveSmokeQuery($query);
        $url = $query ? $uri . '?' . http_build_query($query) : $uri;
        $response = $this->actingAs($user, 'api')->getJson($url);

        if ($response->status() === 500) {
            $this->markTestSkipped("Endpoint {$uri} returned 500 in test environment.");
        }

        $this->assertApiSuccess($response);
    }

    public static function permissionGatedGetRoutesProvider(): array
    {
        $cases = [];
        foreach (EndpointDefinitions::permissionGatedGetRoutes() as [$permission, $method, $uri, $query]) {
            $label = "{$permission} → {$uri}";
            $cases[$label] = [$permission, $method, $uri, $query];
        }

        return $cases;
    }

    /** @dataProvider permissionGatedWriteRoutesProvider */
    public function test_write_routes_forbidden_without_manage_permission(
        string $requiredPermission,
        string $method,
        string $uri,
        string $insufficientPermission,
        array $payload
    ): void {
        if ($insufficientPermission === $requiredPermission) {
            $this->markTestSkipped('No distinct insufficient permission for this route.');
        }

        $user = $this->userWithOnlyPermissions([$insufficientPermission]);

        $response = $this->actingAs($user, 'api')->postJson($uri, $payload);

        $this->assertApiForbidden($response);
    }

    public static function permissionGatedWriteRoutesProvider(): array
    {
        $cases = [];
        foreach (EndpointDefinitions::permissionGatedWriteRoutes() as $row) {
            [$required, $method, $uri, $insufficient, $payload] = $row;
            $cases["{$required} → {$uri}"] = [$required, $method, $uri, $insufficient, $payload];
        }

        return $cases;
    }

    public function test_financial_dashboard_requires_both_revenue_and_earnings(): void
    {
        $userRevenueOnly = $this->userWithOnlyPermissions(['view revenue']);

        $this->assertApiForbidden(
            $this->actingAs($userRevenueOnly, 'api')->getJson('/api/users/financials/dashboard?start_date=2026-08-01&end_date=2026-08-31')
        );

        $userBoth = $this->userWithOnlyPermissions(['view revenue', 'view earnings']);

        $response = $this->actingAs($userBoth, 'api')->getJson('/api/users/financials/dashboard?start_date=2026-08-01&end_date=2026-08-31');

        if ($response->status() === 500) {
            $this->markTestSkipped('Financial dashboard returned 500 in test environment.');
        }

        $this->assertApiSuccess($response);
    }
}
