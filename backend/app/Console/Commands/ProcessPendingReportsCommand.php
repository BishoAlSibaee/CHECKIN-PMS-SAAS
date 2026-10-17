<?php

namespace App\Console\Commands;

use App\Models\ReportExport;
use App\Services\Reports\ReportExportProcessor;
use Illuminate\Console\Command;

class ProcessPendingReportsCommand extends Command
{
    protected $signature = 'reports:process-pending {--limit=5 : Max exports to process per run}';

    protected $description = 'Process pending report export requests (for shared-host cron)';

    public function handle(ReportExportProcessor $processor): int
    {
        $limit = max(1, (int) $this->option('limit'));

        $processor->cleanupExpired();

        $pending = ReportExport::query()
            ->where('status', ReportExport::STATUS_PENDING)
            ->orderBy('id')
            ->limit($limit)
            ->get();

        if ($pending->isEmpty()) {
            $this->info('No pending report exports.');
            return self::SUCCESS;
        }

        foreach ($pending as $export) {
            $this->line("Processing export #{$export->id} ({$export->slug}) → {$export->recipient_email}");
            $processor->process($export);
            $export->refresh();

            if ($export->status === ReportExport::STATUS_READY) {
                $this->info("  ✓ Ready ({$export->row_count} rows)");
            } else {
                $this->error('  ✗ Failed: ' . ($export->error_message ?? 'unknown'));
            }
        }

        return self::SUCCESS;
    }
}
