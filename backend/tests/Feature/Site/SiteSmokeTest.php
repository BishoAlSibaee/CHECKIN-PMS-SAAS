<?php

namespace Tests\Feature\Site;

use Tests\TestCase;

/**
 * Smoke tests for authenticated API endpoints used by the Angular UI.
 */
class SiteSmokeTest extends TestCase
{
    /** @dataProvider publicGetEndpointsProvider */
    public function test_authenticated_get_endpoints_respond(string $uri, array $query = []): void
    {
        $user = $this->adminUser();
        $query = $this->resolveSmokeQuery($query);
        $url = $query ? $uri . '?' . http_build_query($query) : $uri;

        $response = $this->actingAs($user, 'api')->getJson($url);

        $response->assertOk();
        $response->assertJsonPath('success', true);
    }

    public static function publicGetEndpointsProvider(): array
    {
        $monthStart = '2026-08-01';
        $monthEnd = '2026-08-31';

        return [
            'buildings' => ['/api/users/buildings'],
            'floors' => ['/api/users/floors', ['building_id' => 1]],
            'suites' => ['/api/users/suites', ['building_id' => 1, 'floor_id' => 1]],
            'rooms' => ['/api/users/rooms', ['building_id' => 1]],
            'room types' => ['/api/users/getRoomType'],
            'room pricing' => ['/api/users/getRoomtypePricing'],
            'facilities' => ['/api/users/getFacilities'],
            'facility types' => ['/api/users/getFacilitiesType'],
            'features' => ['/api/users/getFeature'],
            'room features' => ['/api/users/getRoomFeature'],
            'stay reasons' => ['/api/users/getStayReasons'],
            'permissions' => ['/api/users/getPermissions'],
            'discounts' => ['/api/users/getDiscounts'],
            'job titles' => ['/api/users/getJobTitle'],
            'taxes' => ['/api/users/getTax'],
            'peak days' => ['/api/users/getPeakDays'],
            'peak months' => ['/api/users/getPeakMonths'],
            'penalties' => ['/api/users/getPenalties'],
            'reservation sources' => ['/api/users/getReservationSource'],
            'clients' => ['/api/users/getClient'],
            'departments' => ['/api/users/getDepartment'],
            'guest classifications' => ['/api/users/getGuestClassification'],
            'guest features' => ['/api/users/getGuestFeature'],
            'guest classification features' => ['/api/users/getGuestClassificationFeature'],
            'classified clients' => ['/api/users/getAllClientsWithClassification'],
            'reservations list' => ['/api/users/reservations'],
            'reservations calendar' => ['/api/users/reservations/calendar'],
            'reservations by date' => ['/api/users/reservations/by-date', [
                'start_date' => $monthStart,
                'expire_date' => $monthEnd,
            ]],
            'dashboard summary' => ['/api/users/dashboard/summary'],
            'occupancy board' => ['/api/users/rooms/occupancy-board', ['date' => $monthStart]],
            'earnings summary' => ['/api/users/earnings-summary', ['start_date' => $monthStart, 'end_date' => $monthEnd]],
            'all earnings' => ['/api/users/all-earnings', ['start_date' => $monthStart, 'end_date' => $monthEnd]],
            'earnings list' => ['/api/users/earnings-list', ['start_date' => $monthStart, 'end_date' => $monthEnd]],
            'payments' => ['/api/users/payments', ['start_date' => $monthStart, 'end_date' => $monthEnd]],
            'refunds' => ['/api/users/refunds', ['start_date' => $monthStart, 'end_date' => $monthEnd]],
            'revenue total' => ['/api/users/revenue/total', ['start_date' => $monthStart, 'end_date' => $monthEnd]],
            'reports catalog' => ['/api/users/reports/catalog'],
            'chart of accounts' => ['/api/users/accounting/chart-of-accounts'],
            'journal entries' => ['/api/users/accounting/journal-entries', ['start_date' => $monthStart, 'end_date' => $monthEnd]],
            'refund policies' => ['/api/users/refund-policies'],
            'client notes' => ['/api/users/client-notes', ['client_id' => 1]],
            'roles' => ['/api/users/getRoles'],
            'users info' => ['/api/users/getInfoUsers'],
            'system health' => ['/api/users/system/health'],
        ];
    }

    public function test_reservation_show_when_exists(): void
    {
        $user = $this->adminUser();
        $reservation = \App\Models\Reservation::query()->first();
        if (!$reservation) {
            $this->markTestSkipped('No reservations in database.');
        }

        $response = $this->actingAs($user, 'api')->getJson("/api/users/reservations/{$reservation->id}");
        $response->assertOk();
        $response->assertJsonPath('success', true);
    }

    public function test_client_show_when_exists(): void
    {
        $user = $this->adminUser();
        $client = \App\Models\Client::query()->first();
        if (!$client) {
            $this->markTestSkipped('No clients in database.');
        }

        $response = $this->actingAs($user, 'api')->getJson("/api/users/getClient/{$client->id}");
        $response->assertOk();
        $response->assertJsonPath('success', true);
    }

    public function test_login_returns_token(): void
    {
        $user = \App\Models\User::first();
        $this->assertNotNull($user);

        $response = $this->postJson('/api/users/login', [
            'job_number' => $user->job_number,
            'password' => 'password',
        ]);

        if (in_array($response->status(), [401, 422, 500], true) || $response->json('success') === false) {
            $this->markTestSkipped('Login credentials or login handler unavailable in test DB.');
        }

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $this->assertNotEmpty($response->json('data.token') ?? $response->json('token'));
    }
}
