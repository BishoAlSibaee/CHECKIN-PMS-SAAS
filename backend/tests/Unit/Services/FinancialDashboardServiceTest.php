<?php

namespace Tests\Unit\Services;

use App\Services\FinancialDashboardService;
use Carbon\Carbon;
use Tests\TestCase;

class FinancialDashboardServiceTest extends TestCase
{
    public function test_bounds_returns_date_range(): void
    {
        $service = app(FinancialDashboardService::class);
        $bounds = $service->bounds();

        $this->assertArrayHasKey('earliest_charge_date', $bounds);
        $this->assertArrayHasKey('earliest_payment_date', $bounds);
    }

    public function test_build_returns_expected_structure(): void
    {
        $service = app(FinancialDashboardService::class);
        $start = Carbon::parse('2026-08-01');
        $end = Carbon::parse('2026-08-31');

        $data = $service->build($start, $end);

        $this->assertIsArray($data);
        $this->assertArrayHasKey('kpis', $data);
        $this->assertArrayHasKey('series', $data);
        $this->assertArrayHasKey('period', $data);
    }
}
