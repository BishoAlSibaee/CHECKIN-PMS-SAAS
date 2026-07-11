<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

class QaFinanceFullCommand extends Command
{
    protected $signature = 'qa:finance-full
                            {--skip-migrate : Skip migrate:fresh --seed}
                            {--skip-tests : Skip PHPUnit suites}';

    protected $description = 'Full finance QA gate: seed, backfill, PHPUnit, reports:verify, demo:verify';

    public function handle(): int
    {
        if (! $this->option('skip-migrate')) {
            $this->info('Running migrate:fresh --seed (full demo)...');
            $code = Artisan::call('migrate:fresh', ['--seed' => true, '--force' => true]);
            if ($code !== 0) {
                $this->error('migrate:fresh --seed failed');

                return self::FAILURE;
            }
            $this->line(Artisan::output());
        }

        $this->info('Backfilling journal entries...');
        Artisan::call('accounting:backfill-journal');
        $this->line(Artisan::output());

        if (! $this->option('skip-tests')) {
            foreach ([
                ['test', ['--compact' => true, '--filter' => 'Finance']],
                ['test', ['--compact' => true, '--filter' => 'Reports']],
                ['test', ['--compact' => true, 'path' => 'tests/Feature/Accounting']],
            ] as [$cmd, $args]) {
                $this->info('Running php artisan '.$cmd.' ...');
                $code = Artisan::call($cmd, $args);
                $this->line(Artisan::output());
                if ($code !== 0) {
                    $this->error("Command {$cmd} failed");

                    return self::FAILURE;
                }
            }
        }

        $this->info('Running reports:verify...');
        $reportsCode = Artisan::call('reports:verify');
        $this->line(Artisan::output());
        if ($reportsCode !== 0) {
            return self::FAILURE;
        }

        $this->info('Running demo:verify...');
        $demoCode = Artisan::call('demo:verify');
        $this->line(Artisan::output());

        return $demoCode === 0 ? self::SUCCESS : self::FAILURE;
    }
}
