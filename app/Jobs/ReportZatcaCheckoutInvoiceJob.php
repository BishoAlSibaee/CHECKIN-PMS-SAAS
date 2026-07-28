<?php

namespace App\Jobs;

use App\Services\Zatca\ZatcaCheckoutReportingService;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Runs after the checkout HTTP response (same PHP process).
 * Does not require a queue worker — important when QUEUE_CONNECTION=redis.
 */
class ReportZatcaCheckoutInvoiceJob
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(public int $reservationId) {}

    public function handle(ZatcaCheckoutReportingService $reporting): void
    {
        if (! $reporting->shouldReportOnCheckout()) {
            return;
        }

        try {
            $invoice = $reporting->reportReservationCheckout($this->reservationId);
            Log::info('ZATCA checkout invoice processed', [
                'reservation_id' => $this->reservationId,
                'zatca_invoice_id' => $invoice->id,
                'status' => $invoice->status,
                'reporting_status' => $invoice->reporting_status,
            ]);
        } catch (\Throwable $e) {
            Log::error('ZATCA checkout invoice job failed', [
                'reservation_id' => $this->reservationId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
