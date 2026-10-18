<?php

namespace Tests\Feature\Reports;

use App\Models\User;
use Database\Seeders\AdminSeeder;
use Database\Seeders\ReservationTestDataSeeder;
use Tests\TestCase;

class ReportOverviewValuesTest extends TestCase
{
    use \Illuminate\Foundation\Testing\DatabaseTransactions;

    protected function connectionsToTransact(): array
    {
        return ['mysql', 'mysql2'];
    }

    public function test_overview_report_returns_non_zero_revenue_for_may_2026(): void
    {
        // Admin user "001" and the May 2026 demo reservations are only
        // seeded by these — this test used to rely on some other,
        // earlier-running test having already run them for its own
        // purposes, with no guarantee of that in a genuinely fresh run.
        $this->artisan('db:seed', [
            '--class' => AdminSeeder::class,
            '--force' => true,
            '--no-interaction' => true,
        ]);
        $this->artisan('db:seed', [
            '--class' => ReservationTestDataSeeder::class,
            '--force' => true,
            '--no-interaction' => true,
        ]);

        $user = User::where('job_number', AdminSeeder::JOB_NUMBER)->first();
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
