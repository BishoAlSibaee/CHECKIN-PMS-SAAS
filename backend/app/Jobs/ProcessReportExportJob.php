<?php

namespace App\Jobs;

use App\Models\ReportExport;
use App\Services\Reports\ReportExportProcessor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessReportExportJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 300;

    public int $tries = 2;

    public function __construct(public int $exportId) {}

    public function handle(ReportExportProcessor $processor): void
    {
        $export = ReportExport::query()->find($this->exportId);
        if ($export === null) {
            return;
        }

        $processor->process($export);
    }
}
