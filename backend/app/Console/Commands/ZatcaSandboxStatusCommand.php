<?php

namespace App\Console\Commands;

use App\Services\Zatca\ZatcaCredentialStore;
use App\Services\Zatca\ZatcaSandboxClient;
use Illuminate\Console\Command;

class ZatcaSandboxStatusCommand extends Command
{
    protected $signature = 'zatca:sandbox-status';

    protected $description = 'Show saved ZATCA sandbox credentials status (without printing secrets)';

    public function handle(ZatcaCredentialStore $store, ZatcaSandboxClient $client): int
    {
        $this->info('Environment: '.config('zatca.environment'));
        $this->line('Base URL: '.$client->baseUrl());

        $credentials = $store->load();
        if ($credentials === null) {
            $this->warn('No credentials found. Run: php artisan zatca:sandbox-onboard');

            return self::FAILURE;
        }

        $this->table(
            ['Field', 'Value'],
            [
                ['Saved at', $credentials['saved_at'] ?? '—'],
                ['VAT', $credentials['vat_number'] ?? '—'],
                ['Compliance request ID', (string) data_get($credentials, 'compliance.request_id', '—')],
                ['Compliance token', $this->mask((string) data_get($credentials, 'compliance.binary_security_token', ''))],
                ['Production token', $this->mask((string) data_get($credentials, 'production.binary_security_token', ''))],
                ['Private key', data_get($credentials, 'paths.private_key', '—')],
                ['CSR', data_get($credentials, 'paths.csr', '—')],
            ]
        );

        $hasProduction = filled(data_get($credentials, 'production.binary_security_token'));
        if ($hasProduction) {
            $this->info('Ready for sandbox Reporting/Clearance invoice tests.');
            $this->line('Print Postman values: php artisan zatca:sandbox-postman');
        } else {
            $this->warn('Compliance only — re-run without --skip-production for Production CSID.');
        }

        return self::SUCCESS;
    }

    private function mask(string $value): string
    {
        if ($value === '') {
            return '—';
        }

        if (strlen($value) <= 12) {
            return str_repeat('*', strlen($value));
        }

        return substr($value, 0, 6).'…'.substr($value, -6).' ('.strlen($value).' chars)';
    }
}
