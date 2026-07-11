<?php

namespace App\Console\Commands;

use App\Services\Reports\ReportVerificationService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

class ReportsVerifyCommand extends Command
{
    protected $signature = 'reports:verify
                            {--start=2026-08-01 : Period start date}
                            {--end=2026-08-31 : Period end date}
                            {--dump-golden : Write golden metrics JSON to tests/Fixtures}';

    protected $description = 'Verify all 23 report slugs against canonical financial services';

    public function handle(ReportVerificationService $verification): int
    {
        $start = Carbon::parse($this->option('start'));
        $end = Carbon::parse($this->option('end'));

        if ($this->option('dump-golden')) {
            Artisan::call('accounting:backfill-journal');
            $golden = $verification->dumpGolden($start, $end);
            $path = base_path('tests/Fixtures/august_2026_golden.json');
            File::ensureDirectoryExists(dirname($path));
            File::put($path, json_encode($golden, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE).PHP_EOL);
            $this->info('Golden metrics written to tests/Fixtures/august_2026_golden.json');

            return self::SUCCESS;
        }

        $this->info("=== Reports Verify ({$start->toDateString()} → {$end->toDateString()}) ===");
        Artisan::call('accounting:backfill-journal');
        $results = $verification->verifyAll($start, $end);

        $rows = [];
        $failures = 0;
        foreach ($results as $result) {
            $rows[] = [
                $result['slug'],
                $result['pass'] ? 'PASS' : 'FAIL',
                $result['message'],
            ];
            if (! $result['pass']) {
                $failures++;
            }
        }

        $this->table(['Slug', 'Result', 'Message'], $rows);
        $this->info('Summary: '.(count($results) - $failures).' PASS, '.$failures.' FAIL');

        return $failures > 0 ? self::FAILURE : self::SUCCESS;
    }
}
