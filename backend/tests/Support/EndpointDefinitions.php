<?php

namespace Tests\Support;

/**
 * Catalog of permission-gated endpoints for RBAC and smoke coverage.
 */
final class EndpointDefinitions
{
    /** @return list<array{string, string, string, array<string, mixed>}> */
    public static function permissionGatedGetRoutes(): array
    {
        $monthStart = '2026-08-01';
        $monthEnd = '2026-08-31';

        return [
            ['view buildings', 'GET', '/api/users/buildings', []],
            ['view floors', 'GET', '/api/users/floors', ['building_id' => 1]],
            ['view suites', 'GET', '/api/users/suites', ['building_id' => 1, 'floor_id' => 1]],
            ['view rooms', 'GET', '/api/users/rooms', ['building_id' => 1]],
            ['view room types', 'GET', '/api/users/getRoomType', []],
            ['view room types', 'GET', '/api/users/getRoomtypePricing', []],
            ['manage facilities', 'GET', '/api/users/getFacilities', []],
            ['manage features', 'GET', '/api/users/getFeature', []],
            ['create reservations', 'GET', '/api/users/getStayReasons', []],
            ['manage permissions', 'GET', '/api/users/getPermissions', []],
            ['create reservations', 'GET', '/api/users/getDiscounts', []],
            ['manage taxes', 'GET', '/api/users/getTax', []],
            ['manage peak days', 'GET', '/api/users/getPeakDays', []],
            ['manage peak months', 'GET', '/api/users/getPeakMonths', []],
            ['update reservations', 'GET', '/api/users/getPenalties', []],
            ['create reservations', 'GET', '/api/users/getReservationSource', []],
            ['view clients', 'GET', '/api/users/getClient', []],
            ['manage guest classifications', 'GET', '/api/users/getGuestClassification', []],
            ['view reservations', 'GET', '/api/users/reservations', []],
            ['view reservations', 'GET', '/api/users/reservations/calendar', []],
            ['view earnings', 'GET', '/api/users/earnings-summary', ['start_date' => $monthStart, 'end_date' => $monthEnd]],
            ['view reports', 'GET', '/api/users/reports/catalog', []],
            ['view accounting reports', 'GET', '/api/users/accounting/chart-of-accounts', []],
            ['view revenue', 'GET', '/api/users/revenue/total', ['start_date' => $monthStart, 'end_date' => $monthEnd]],
            ['manage refund policies', 'GET', '/api/users/refund-policies', []],
            ['manage client notes', 'GET', '/api/users/client-notes', ['client_id' => 1]],
            ['manage roles', 'GET', '/api/users/getRoles', []],
            ['manage users', 'GET', '/api/users/getInfoUsers', []],
            ['view users', 'GET', '/api/users/system/health', []],
            ['manage night audit', 'GET', '/api/users/night-audit/runs', []],
        ];
    }

    /** @return list<array{string, string, string, string, array<string, mixed>}> */
    public static function permissionGatedWriteRoutes(): array
    {
        return [
            ['manage buildings', 'POST', '/api/users/building', 'view buildings', []],
            ['manage floors', 'POST', '/api/users/addFloor', 'view floors', ['building_id' => 1, 'number' => 99999]],
            ['manage taxes', 'POST', '/api/users/addTax', 'manage taxes', []],
            ['manage discounts', 'POST', '/api/users/addDiscount', 'manage discounts', []],
            ['manage stay reasons', 'POST', '/api/users/addStayReason', 'create reservations', []],
            ['manage clients', 'POST', '/api/users/addClient', 'view clients', []],
            ['manage departments', 'POST', '/api/users/addDepartment', 'view users', []],
            ['manage penalties', 'POST', '/api/users/addPenaltie', 'update reservations', []],
            ['manage reservation sources', 'POST', '/api/users/addReservationSource', 'create reservations', []],
            ['create reservations', 'POST', '/api/users/makeReservation', 'view reservations', []],
            ['manage journal entries', 'POST', '/api/users/accounting/journal-entries', 'view accounting reports', []],
            ['close accounting period', 'POST', '/api/users/accounting/periods/close', 'view accounting reports', []],
            ['manage night audit', 'POST', '/api/users/night-audit/run', 'view accounting reports', []],
        ];
    }

    /** @return list<array{string, string, array<string, mixed>}> */
    public static function unauthenticatedRoutes(): array
    {
        return [
            ['GET', '/api/users/buildings', []],
            ['GET', '/api/users/reservations', []],
            ['GET', '/api/users/getClient', []],
            ['GET', '/api/users/dashboard/summary', []],
            ['GET', '/api/users/reports/catalog', []],
            ['POST', '/api/users/building', ['name' => 'X', 'number' => 1]],
        ];
    }
}
