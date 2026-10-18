<?php

namespace Tests\Feature\Reports;

use App\Services\Reports\ReportCatalog;
use Tests\TestCase;

class AllReportsSlugTest extends TestCase
{
    use \Illuminate\Foundation\Testing\DatabaseTransactions;

    protected function connectionsToTransact(): array
    {
        return ['mysql', 'mysql2'];
    }

    public static function reportSlugProvider(): array
    {
        return array_map(fn (string $slug) => [$slug], ReportCatalog::allSlugs());
    }

    /** @dataProvider reportSlugProvider */
    public function test_report_slug_returns_valid_payload(string $slug): void
    {
        $user = $this->userWithApiPermissions([
            'view reports', 'view financial reports', 'view accounting reports',
        ]);

        $query = http_build_query(array_merge(
            ['start_date' => '2026-08-01', 'end_date' => '2026-08-31'],
            $slug === 'general-ledger' ? ['account_type' => 'asset'] : []
        ));

        $response = $this->actingAs($user, 'api')->getJson(
            "/api/users/reports/{$slug}?{$query}"
        );

        $response->assertOk();
        $response->assertJsonPath('success', true);

        $data = $response->json('data');
        $this->assertIsArray($data);
        $this->assertArrayHasKey('columns', $data);
        $this->assertArrayHasKey('rows', $data);
        $this->assertIsArray($data['columns']);
        $this->assertIsArray($data['rows']);

        foreach ($data['columns'] as $column) {
            $this->assertArrayHasKey('key', $column);
            $this->assertArrayHasKey('label', $column);
        }
    }
}
