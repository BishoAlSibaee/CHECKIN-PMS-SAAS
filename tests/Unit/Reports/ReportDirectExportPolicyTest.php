<?php

namespace Tests\Unit\Reports;

use App\Services\Reports\ReportDirectExportPolicy;
use Tests\TestCase;

class ReportDirectExportPolicyTest extends TestCase
{
    public function test_allows_direct_download_within_limit(): void
    {
        config(['reports.max_direct_download_rows' => 40]);

        $this->assertTrue(ReportDirectExportPolicy::allowsDirectDownload(40));
        $this->assertFalse(ReportDirectExportPolicy::allowsDirectDownload(41));
    }

    public function test_assert_throws_when_over_limit(): void
    {
        config(['reports.max_direct_download_rows' => 10]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('exceeds the direct download limit of 10');

        ReportDirectExportPolicy::assertAllowsDirectDownload(11);
    }

    public function test_meta_flags_require_email_or_filters(): void
    {
        config(['reports.max_direct_download_rows' => 100]);

        $meta = ReportDirectExportPolicy::metaForRowCount(250);

        $this->assertSame(100, $meta['max_direct_download_rows']);
        $this->assertFalse($meta['direct_download_allowed']);
        $this->assertTrue($meta['requires_email_or_filters']);
    }
}
