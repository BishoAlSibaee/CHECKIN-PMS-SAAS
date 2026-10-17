<?php

namespace Tests\Feature\Reports;

use App\Models\User;
use Tests\TestCase;

class ReportOverviewValuesTest extends TestCase
{
    public function test_overview_report_returns_non_zero_revenue_for_may_2026(): void
    {
        $user = User::where('job_number', '001')->first();
        $this->assertNotNull($user);

        $response = $this->actingAs($user, 'api')->getJson(
            '/api/users/reports/overview?start_date=2026-05-01&end_date=2026-05-31'
        );

        $response->assertOk();
        $response->assertJsonPath('success', true);

        $summary = $response->json('data.summary');
        $this->assertNotEmpty($summary);

        $accrual = collect($summary)->firstWhere('label', 'Accrual revenue');
        $this->assertNotNull($accrual);
        $this->assertGreaterThan(0, (float) $accrual['value'], 'Accrual revenue should not be zero for May 2026 demo data');
    }
}
