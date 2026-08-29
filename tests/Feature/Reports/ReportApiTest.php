<?php

namespace Tests\Feature\Reports;

use App\Models\Room;
use Tests\Support\FinanceTestBootstrap;
use Tests\TestCase;

class ReportApiTest extends TestCase
{
    use FinanceTestBootstrap;

    public function test_reports_catalog_requires_auth(): void
    {
        $response = $this->getJson('/api/users/reports/catalog');
        $response->assertStatus(401);
    }

    public function test_revenue_summary_report(): void
    {
        $user = $this->userWithApiPermissions(['view reports', 'view financial reports']);
        $response = $this->actingAs($user, 'api')->getJson(
            '/api/users/reports/revenue-summary?start_date=2026-08-01&end_date=2026-08-31'
        );

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $this->assertArrayHasKey('columns', $response->json('data'));
        $this->assertArrayHasKey('rows', $response->json('data'));
    }

    public function test_trial_balance_report(): void
    {
        $user = $this->userWithApiPermissions(['view reports', 'view accounting reports']);
        $response = $this->actingAs($user, 'api')->getJson(
            '/api/users/reports/trial-balance?start_date=2026-08-01&end_date=2026-08-31'
        );

        $response->assertOk();
        $response->assertJsonPath('success', true);
    }

    public function test_chart_of_accounts(): void
    {
        $user = $this->userWithApiPermissions(['view reports', 'view accounting reports']);
        $response = $this->actingAs($user, 'api')->getJson(
            '/api/users/reports/chart-of-accounts?start_date=2026-08-01&end_date=2026-08-31'
        );
        $response->assertOk();
        $response->assertJsonPath('success', true);

        $rows = $response->json('data.rows');
        $this->assertNotEmpty($rows);
        $this->assertArrayHasKey('account_id', $rows[0]);
    }

    public function test_chart_of_accounts_filters_by_type_and_search(): void
    {
        $user = $this->userWithApiPermissions(['view reports', 'view accounting reports']);
        $response = $this->actingAs($user, 'api')->getJson(
            '/api/users/reports/chart-of-accounts?start_date=2026-08-01&end_date=2026-08-31&account_type=expense&search=5020'
        );

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $rows = $response->json('data.rows') ?? [];
        $this->assertNotEmpty($rows);
        foreach ($rows as $row) {
            $this->assertSame('expense', $row['type']);
        }
    }

    public function test_trial_balance_hide_zero_filter(): void
    {
        $user = $this->userWithApiPermissions(['view reports', 'view accounting reports']);
        $all = $this->actingAs($user, 'api')->getJson(
            '/api/users/reports/trial-balance?start_date=2026-08-01&end_date=2026-08-31'
        );
        $filtered = $this->actingAs($user, 'api')->getJson(
            '/api/users/reports/trial-balance?start_date=2026-08-01&end_date=2026-08-31&hide_zero=true'
        );

        $all->assertOk();
        $filtered->assertOk();
        $this->assertLessThanOrEqual(
            count($all->json('data.rows') ?? []),
            count($filtered->json('data.rows') ?? [])
        );
    }

    public function test_general_ledger_requires_account_type(): void
    {
        $user = $this->userWithApiPermissions(['view reports', 'view accounting reports']);
        $response = $this->actingAs($user, 'api')->getJson(
            '/api/users/reports/general-ledger?start_date=2026-08-01&end_date=2026-08-31'
        );

        $response->assertStatus(422);
        $response->assertJsonPath('success', false);
    }

    public function test_general_ledger_filters_by_account_type(): void
    {
        $user = $this->userWithApiPermissions(['view reports', 'view accounting reports']);
        $response = $this->actingAs($user, 'api')->getJson(
            '/api/users/reports/general-ledger?start_date=2026-08-01&end_date=2026-08-31&account_type=expense'
        );

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $this->assertSame('Expense', $response->json('data.summary.0.value'));
    }

    public function test_general_ledger_accepts_account_filter(): void
    {
        $user = $this->userWithApiPermissions(['view reports', 'view accounting reports']);
        $coa = $this->actingAs($user, 'api')->getJson(
            '/api/users/reports/chart-of-accounts?start_date=2026-08-01&end_date=2026-08-31'
        );
        $coa->assertOk();
        $accountId = $coa->json('data.rows.0.account_id');
        $accountType = $coa->json('data.rows.0.type');
        $this->assertNotNull($accountId);
        $this->assertNotNull($accountType);

        $response = $this->actingAs($user, 'api')->getJson(
            '/api/users/reports/general-ledger?start_date=2026-08-01&end_date=2026-08-31&account_id='
            .$accountId.'&account_type='.$accountType
        );

        $response->assertOk();
        $response->assertJsonPath('success', true);
    }

    public function test_closing_package_rows_expose_detail_slug_for_navigation(): void
    {
        $user = $this->userWithApiPermissions(['view reports', 'view financial reports']);
        $response = $this->actingAs($user, 'api')->getJson(
            '/api/users/reports/closing-package?start_date=2026-08-01&end_date=2026-08-31'
        );

        $response->assertOk();
        $response->assertJsonPath('success', true);

        $rows = $response->json('data.rows') ?? [];
        $this->assertNotEmpty($rows);

        $expected = [
            'Accrual revenue (incl. tax)' => 'accrual-revenue',
            'Accrual subtotal (pre-tax)' => 'accrual-revenue',
            'Tax (accrual)' => 'tax',
            'Cash in' => 'cash-box',
            'Cash out (refunds)' => 'payments-refunds',
            'Cash net' => 'cash-box',
            'Accounts receivable (open balances)' => 'ar-aging',
            'Accrual − cash net' => 'accrual-cash-reconciliation',
        ];

        $byMetric = collect($rows)->keyBy('metric');
        foreach ($expected as $metric => $slug) {
            $this->assertSame($slug, $byMetric->get($metric)['detail_slug'] ?? null, $metric);
        }

        $columns = collect($response->json('data.columns'))->pluck('key')->all();
        $this->assertSame(['metric', 'amount'], $columns);
    }

    public function test_financial_audit_log_report(): void
    {
        $this->bootstrapFinanceData();

        $user = $this->userWithApiPermissions(['view reports', 'view accounting reports']);
        $response = $this->actingAs($user, 'api')->getJson(
            '/api/users/reports/financial-audit-log?start_date='.self::FINANCE_PERIOD_START.'&end_date='.self::FINANCE_PERIOD_END
        );

        $response->assertOk();
        $response->assertJsonPath('success', true);

        $columns = collect($response->json('data.columns'))->pluck('key')->all();
        $this->assertSame(['event_date', 'operation', 'description', 'reference', 'performed_by'], $columns);

        $summary = collect($response->json('data.summary'));
        $totalEvents = (int) ($summary->firstWhere('label', 'Total events')['value'] ?? 0);
        $this->assertGreaterThan(0, $totalEvents, 'Audit log should include posted journal activity for the finance fixture period');
        $this->assertNotEmpty($response->json('data.rows'));

        $linkedRow = collect($response->json('data.rows'))->first(
            fn (array $row) => ! empty($row['reservation_id']) || ! empty($row['detail_slug'])
        );
        $this->assertNotNull($linkedRow, 'Audit log rows should expose reservation_id or detail_slug for navigation');
    }

    public function test_occupancy_available_rooms_follow_booked_room_nights(): void
    {
        $this->bootstrapFinanceData();

        $user = $this->userWithApiPermissions(['view reports', 'view financial reports']);
        $start = self::FINANCE_PERIOD_START;
        $end = self::FINANCE_PERIOD_END;

        $response = $this->actingAs($user, 'api')->getJson(
            "/api/users/reports/occupancy?start_date={$start}&end_date={$end}"
        );

        $response->assertOk();
        $response->assertJsonPath('success', true);

        $columnKeys = collect($response->json('data.columns'))->pluck('key')->all();
        $this->assertContains('available_rooms', $columnKeys);
        $this->assertNotContains('vacant', $columnKeys);

        $totalRooms = Room::query()
            ->where('active', 1)
            ->whereNotIn('roomStatus', [3, 4])
            ->count();

        foreach ($response->json('data.rows') as $row) {
            $booked = (int) $row['room_nights'];
            $expectedAvailable = max(0, $totalRooms - $booked);
            $this->assertSame(
                $expectedAvailable,
                (int) $row['available_rooms'],
                "available_rooms for {$row['date']}"
            );
        }

        $summary = collect($response->json('data.summary'));
        $this->assertSame(
            $totalRooms,
            (int) ($summary->firstWhere('label', 'Total rooms')['value'] ?? 0)
        );
    }
}
