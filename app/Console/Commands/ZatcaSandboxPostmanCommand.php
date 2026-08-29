<?php

namespace App\Console\Commands;

use App\Services\Zatca\ZatcaCredentialStore;
use App\Services\Zatca\ZatcaSandboxClient;
use Illuminate\Console\Command;

class ZatcaSandboxPostmanCommand extends Command
{
    protected $signature = 'zatca:sandbox-postman
        {--show-secrets : Print full tokens/secrets for Postman (sensitive)}';

    protected $description = 'Print ZATCA sandbox URLs and auth values for Postman testing';

    public function handle(ZatcaCredentialStore $store, ZatcaSandboxClient $client): int
    {
        $credentials = $store->load();
        if ($credentials === null) {
            $this->error('No credentials. Run: php artisan zatca:sandbox-onboard');

            return self::FAILURE;
        }

        $base = $client->baseUrl();
        $showSecrets = (bool) $this->option('show-secrets');

        $complianceToken = (string) data_get($credentials, 'compliance.binary_security_token', '');
        $complianceSecret = (string) data_get($credentials, 'compliance.secret', '');
        $productionToken = (string) data_get($credentials, 'production.binary_security_token', '');
        $productionSecret = (string) data_get($credentials, 'production.secret', '');
        $requestId = (string) data_get($credentials, 'compliance.request_id', '');

        $this->info('ZATCA Sandbox — Postman cheat sheet');
        $this->newLine();
        $this->line('Base URL: '.$base);
        $this->line('Accept-Version header: V2');
        $this->newLine();

        $this->table(['Request', 'Method + Path'], [
            ['Compliance CSID (already done)', 'POST '.$base.'/compliance'],
            ['Production CSID (already done)', 'POST '.$base.'/production/csids'],
            ['Reporting (simplified invoice)', 'POST '.$base.'/invoices/reporting/single'],
            ['Clearance (standard invoice)', 'POST '.$base.'/invoices/clearance/single'],
        ]);

        $this->newLine();
        $this->warn('For Reporting/Clearance use Production Basic Auth:');
        if ($showSecrets) {
            $this->line('Username (binarySecurityToken):');
            $this->line($productionToken);
            $this->line('Password (secret):');
            $this->line($productionSecret);
        } else {
            $this->line('Username: '.(strlen($productionToken) ? substr($productionToken, 0, 8).'… (hidden)' : '—'));
            $this->line('Password: '.(strlen($productionSecret) ? '******** (hidden)' : '—'));
            $this->comment('Re-run with --show-secrets to print full values for Postman.');
        }

        $this->newLine();
        $this->line('Compliance request ID: '.$requestId);
        if ($showSecrets) {
            $this->line('Compliance token: '.$complianceToken);
            $this->line('Compliance secret: '.$complianceSecret);
        }

        $csrPath = (string) data_get($credentials, 'paths.csr', '');
        if ($csrPath !== '' && is_file($csrPath)) {
            $this->newLine();
            $this->line('CSR path: '.$csrPath);
            $this->line('CSR Base64 (for re-testing /compliance):');
            $this->line(base64_encode((string) file_get_contents($csrPath)));
        }

        $this->newLine();
        $this->comment('Reporting body shape:');
        $this->line('{ "invoiceHash": "...", "uuid": "...", "invoice": "BASE64_SIGNED_XML" }');

        return self::SUCCESS;
    }
}
