<?php

namespace App\Console\Commands;

use App\Services\Zatca\ZatcaSandboxInvoiceService;
use Illuminate\Console\Command;
use Throwable;

class ZatcaSandboxReportTestCommand extends Command
{
    protected $signature = 'zatca:sandbox-report-test';

    protected $description = 'Build, sign, and report one simplified sandbox invoice; also write a Postman payload file';

    public function handle(ZatcaSandboxInvoiceService $invoices): int
    {
        $this->info('Building + signing + reporting simplified sandbox invoice...');

        try {
            $result = $invoices->reportSimplifiedTestInvoice();
        } catch (Throwable $e) {
            $this->error($e->getMessage());
            if ($this->output->isVerbose() && $e->getPrevious()) {
                $this->line($e->getPrevious()->getMessage());
                $this->line($e->getPrevious()->getTraceAsString());
            }

            return self::FAILURE;
        }

        $this->table(['Field', 'Value'], [
            ['Invoice ID', $result['invoice_id']],
            ['UUID', $result['uuid']],
            ['Invoice hash', $result['invoice_hash']],
            ['HTTP status', (string) ($result['http_status'] ?? '—')],
            ['Reporting status', (string) ($result['reporting_status'] ?? '—')],
            ['Signed XML', $result['signed_xml_path']],
            ['Postman payload', $result['postman_payload_path']],
        ]);

        if (! empty($result['error'])) {
            $this->error('Reporting API error: '.$result['error']);
            if (! empty($result['raw'])) {
                $this->line(json_encode($result['raw'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            } else {
                $this->warn('No response body returned with the error.');
            }
            $this->warn('Signed XML + Postman payload were still saved — you can retry from Postman.');

            return self::FAILURE;
        }

        if (($result['reporting_status'] ?? null) === 'REPORTED' || (int) ($result['http_status'] ?? 0) === 200) {
            $this->info('Sandbox reporting succeeded.');
        } else {
            $this->warn('Request finished but status is not REPORTED. Inspect raw response below.');
        }

        if (! empty($result['raw'])) {
            $this->line(json_encode($result['raw'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }

        $this->newLine();
        $this->comment('Postman: import body/auth from postman_reporting_payload.json (see path above).');

        return self::SUCCESS;
    }
}
