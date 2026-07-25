<?php

namespace App\Console\Commands;

use App\Services\Zatca\ZatcaCredentialStore;
use App\Services\Zatca\ZatcaCsrService;
use App\Services\Zatca\ZatcaSandboxClient;
use Illuminate\Console\Command;
use Throwable;

class ZatcaSandboxOnboardCommand extends Command
{
    protected $signature = 'zatca:sandbox-onboard
        {--otp= : Sandbox OTP (defaults to ZATCA_OTP / 123345)}
        {--skip-production : Stop after Compliance CSID}';

    protected $description = 'Generate CSR and onboard against ZATCA Integration Sandbox (Compliance + Production CSID)';

    public function handle(
        ZatcaCsrService $csrService,
        ZatcaSandboxClient $client,
        ZatcaCredentialStore $store,
    ): int {
        $this->info('ZATCA environment: '.config('zatca.environment'));
        $this->line('Base URL: '.$client->baseUrl());

        try {
            $this->line('Generating private key + CSR...');
            $csr = $csrService->generateAndStore();
            $this->info('CSR written: '.$csr['csr_path']);
            $this->line('VAT (org id): '.$csr['config']['organization_identifier']);
            $this->line('Template: '.$csr['config']['certificate_template']);

            $otp = $this->option('otp') ?: (string) config('zatca.otp');
            $this->line("Requesting Compliance CSID (OTP={$otp})...");
            $compliance = $client->requestComplianceCsid($csr['csr_base64'], $otp);
            $this->info('Compliance CSID issued. requestID='.(string) ($compliance['requestID'] ?? ''));

            $payload = [
                'environment' => config('zatca.environment'),
                'vat_number' => $csr['config']['organization_identifier'],
                'compliance' => [
                    'request_id' => $compliance['requestID'] ?? null,
                    'disposition_message' => $compliance['dispositionMessage'] ?? null,
                    'binary_security_token' => $compliance['binarySecurityToken'],
                    'secret' => $compliance['secret'],
                ],
                'paths' => [
                    'private_key' => $csr['private_key_path'],
                    'csr' => $csr['csr_path'],
                ],
            ];

            if (! $this->option('skip-production')) {
                $requestId = (string) ($compliance['requestID'] ?? '');
                if ($requestId === '') {
                    throw new \RuntimeException('Compliance response missing requestID; cannot request Production CSID.');
                }

                $this->line('Requesting Production CSID (onboarding)...');
                $production = $client->requestProductionCsid(
                    $requestId,
                    $compliance['binarySecurityToken'],
                    $compliance['secret'],
                );
                $this->info('Production CSID issued.');
                $payload['production'] = [
                    'request_id' => $production['requestID'] ?? null,
                    'disposition_message' => $production['dispositionMessage'] ?? null,
                    'binary_security_token' => $production['binarySecurityToken'],
                    'secret' => $production['secret'],
                ];
            }

            $path = $store->save($payload);
            $this->newLine();
            $this->info('Sandbox onboard succeeded.');
            $this->line('Credentials saved to: '.$path);
            $this->warn('Do not commit storage/app/zatca/* secrets to git.');

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
