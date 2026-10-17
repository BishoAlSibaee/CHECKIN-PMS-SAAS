<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

class DemoVerifyCommand extends Command
{
    protected $signature = 'demo:verify';

    protected $description = 'Verify demo data integrity (finance audit + quick checks)';

    public function handle(): int
    {
        $this->info('Running finance audit...');
        $exitCode = Artisan::call('finance:audit');
        $this->line(Artisan::output());

        if ($exitCode !== self::SUCCESS) {
            $this->error('Demo verification failed. Re-run: php artisan migrate:fresh --seed');
            return self::FAILURE;
        }

        $this->info('Demo verification passed.');
        return self::SUCCESS;
    }
}
