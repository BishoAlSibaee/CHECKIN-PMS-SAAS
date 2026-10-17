<?php

namespace App\Observers;

use App\Models\PaymentVoucher;
use App\Models\ReservationPay;
use App\Services\Accounting\AccountingPostingService;
use App\Services\LoyaltyPointsService;
use App\Services\Zatca\ZatcaCheckoutReportingService;
use Illuminate\Support\Facades\Log;

class ReservationPayObserver
{
    public function __construct(
        private ZatcaCheckoutReportingService $zatcaCheckoutReportingService,
        private LoyaltyPointsService $loyaltyPointsService,
        private AccountingPostingService $accountingPostingService,
    ) {}

    public function created(ReservationPay $payment): void
    {
        $isRefund = (int) $payment->type === ReservationPay::TYPE_REFUND;

        $voucher = PaymentVoucher::create([
            'reservation_pay_id' => $payment->id,
            'type' => $isRefund ? PaymentVoucher::TYPE_DISBURSEMENT : PaymentVoucher::TYPE_RECEIPT,
            'amount' => $payment->pay,
            'method' => $payment->payment_source,
            'issued_at' => $payment->created_at ?? now(),
        ]);
        $voucher->voucher_number = ($isRefund ? 'DV-' : 'RV-').str_pad((string) $voucher->id, 6, '0', STR_PAD_LEFT);
        $voucher->save();

        // A refund against a reservation that's already been fully invoiced and
        // reported to ZATCA needs a credit note — ZATCA has no concept of quietly
        // amending a previously reported simplified invoice. reportCreditNote()
        // itself no-ops (returns null) when there's no prior reported invoice to
        // correct, so this is safe to call for every refund unconditionally.
        if ($isRefund) {
            try {
                $this->zatcaCheckoutReportingService->reportCreditNote((int) $payment->reservation_id, (float) $payment->pay);
            } catch (\Throwable $e) {
                Log::warning('ZATCA credit note failed for refund', [
                    'reservation_pay_id' => $payment->id,
                    'reservation_id' => $payment->reservation_id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        try {
            if ($isRefund) {
                $this->loyaltyPointsService->reverseForRefund($payment);
            } elseif ((int) $payment->type === ReservationPay::TYPE_PAYMENT) {
                $this->loyaltyPointsService->awardForPayment($payment);
            }
        } catch (\Throwable $e) {
            Log::warning('Loyalty points adjustment failed', [
                'reservation_pay_id' => $payment->id,
                'reservation_id' => $payment->reservation_id,
                'error' => $e->getMessage(),
            ]);
        }

        try {
            $this->accountingPostingService->postOtaCommission($payment);
        } catch (\Throwable $e) {
            Log::warning('OTA commission posting failed', [
                'reservation_pay_id' => $payment->id,
                'reservation_id' => $payment->reservation_id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
