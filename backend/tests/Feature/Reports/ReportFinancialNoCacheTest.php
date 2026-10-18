<?php

namespace Tests\Feature\Reports;

use App\Services\Reports\ReportQueryService;
use ReflectionClass;
use Tests\TestCase;

class ReportFinancialNoCacheTest extends TestCase
{
    use \Illuminate\Foundation\Testing\DatabaseTransactions;

    protected function connectionsToTransact(): array
    {
        return ['mysql', 'mysql2'];
    }

    public function test_only_occupancy_report_method_uses_report_cache_wrapper(): void
    {
        $reflection = new ReflectionClass(ReportQueryService::class);
        $source = file_get_contents($reflection->getFileName());

        $this->assertIsString($source);
        $this->assertSame(1, substr_count($source, 'OccupancyReportCache::remember'));
        $this->assertSame(1, substr_count($source, 'OccupancyBoardCache::remember'));
        $this->assertStringNotContainsString('use Illuminate\Support\Facades\Cache', $source);
        $this->assertStringNotContainsString('ReportCatalogCache', $source);
    }
}
