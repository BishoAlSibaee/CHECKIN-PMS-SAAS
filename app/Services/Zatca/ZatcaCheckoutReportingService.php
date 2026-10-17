<?php

namespace App\Services\Zatca;

use App\Models\Invoice as InvoiceRecord;
use App\Models\Reservation;
use App\Models\ZatcaInvoice;
use DateTime;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Saleh7\Zatca\AdditionalDocumentReference;
use Saleh7\Zatca\Address;
use Saleh7\Zatca\AllowanceCharge;
use Saleh7\Zatca\Attachment;
use Saleh7\Zatca\BillingReference;
use Saleh7\Zatca\ClassifiedTaxCategory;
use Saleh7\Zatca\ExtensionContent;
use Saleh7\Zatca\GeneratorInvoice;
use Saleh7\Zatca\Helpers\Certificate;
use Saleh7\Zatca\Invoice;
use Saleh7\Zatca\InvoiceLine;
use Saleh7\Zatca\InvoiceSigner;
use Saleh7\Zatca\InvoiceType;
use Saleh7\Zatca\Item;
use Saleh7\Zatca\LegalEntity;
use Saleh7\Zatca\LegalMonetaryTotal;
use Saleh7\Zatca\Party;
use Saleh7\Zatca\PartyTaxScheme;
use Saleh7\Zatca\PaymentMeans;
use Saleh7\Zatca\Price;
use Saleh7\Zatca\Signature;
use Saleh7\Zatca\SignatureInformation;
use Saleh7\Zatca\TaxCategory;
use Saleh7\Zatca\TaxScheme;
use Saleh7\Zatca\TaxSubTotal;
use Saleh7\Zatca\TaxTotal;
use Saleh7\Zatca\UBLDocumentSignatures;
use Saleh7\Zatca\UBLExtension;
use Saleh7\Zatca\UBLExtensions;
use Saleh7\Zatca\UnitCode;
use Saleh7\Zatca\ZatcaAPI;

class ZatcaCheckoutReportingService
{
    private const FIRST_PIH = 'NWZlY2ViNjZmZmM4NmYzOGQ5NTI3ODZjNmQ2OTZjNzljMmRiYzIzOWRkNGU5MWI0NjcyOWQ3M2EyN2ZiNTdlOQ==';

    public function __construct(
        private ZatcaCredentialStore $store,
        private HotelTaxResolver $taxResolver,
        private \App\Services\HotelResolverService $hotelResolver,
    ) {}

    public function shouldReportOnCheckout(): bool
    {
        return (bool) config('zatca.enabled', true)
            && (bool) config('zatca.auto_report_on_checkout', true);
    }

    public function reportReservationCheckout(int $reservationId): ZatcaInvoice
    {
        $reservation = Reservation::query()
            ->with(['client', 'reservationRooms.room.building'])
            ->findOrFail($reservationId);

        $hotelId = $this->hotelResolver->forReservation($reservation);
        $taxHotelId = $this->taxResolver->resolveEffectiveTaxHotelId($hotelId);

        $subtotal = round((float) $reservation->subtotal, 2);
        $tax = round((float) $reservation->taxes, 2);
        $total = round((float) $reservation->total, 2);

        if ($total <= 0.005) {
            $skip = ZatcaInvoice::query()->where('reservation_id', $reservationId)->whereNull('invoice_id')->first()
                ?? new ZatcaInvoice([
                    'reservation_id' => $reservationId,
                    'invoice_number' => 'HOT-R'.$reservationId.'-'.now()->format('YmdHis'),
                    'uuid' => (string) Str::uuid(),
                ]);
            $skip->fill([
                'status' => ZatcaInvoice::STATUS_SKIPPED,
                'error_message' => 'Reservation total is zero; ZATCA report skipped.',
            ]);
            $skip->save();

            return $skip;
        }

        if ($subtotal <= 0.005 && $tax > 0) {
            $subtotal = round($total - $tax, 2);
        }
        if ($subtotal <= 0.005) {
            $subtotal = round($total / 1.15, 2);
            $tax = round($total - $subtotal, 2);
        }

        // The business invoice record exists independently of ZATCA reporting
        // success — a guest's printed invoice/receipt must not depend on the
        // sandbox/production reporting call succeeding. Snapshot amounts now so
        // later reservation edits (refunds, date changes) can't retroactively
        // change an already-issued invoice's recorded totals.
        $invoiceRecord = InvoiceRecord::query()->firstOrCreate(
            ['reservation_id' => $reservationId, 'type' => InvoiceRecord::TYPE_TAX_INVOICE],
            [
                'hotel_id' => $hotelId,
                'tax_hotel_id' => $taxHotelId,
                'status' => InvoiceRecord::STATUS_ISSUED,
                'subtotal' => $subtotal,
                'tax_amount' => $tax,
                'total' => $total,
                'currency' => 'SAR',
                'line_items' => [[
                    'name' => 'Hotel stay #'.$reservationId,
                    'quantity' => max(1, (int) ($reservation->nights ?: 1)),
                    'unit_price' => round($subtotal / max(1, (int) ($reservation->nights ?: 1)), 2),
                    'line_total' => $subtotal,
                    'tax_amount' => $tax,
                ]],
                'issued_at' => now(),
            ]
        );
        if ($invoiceRecord->wasRecentlyCreated) {
            $invoiceRecord->invoice_number = $this->taxResolver->nextInvoiceNumber($taxHotelId, 'INV-');
            $invoiceRecord->save();
        }

        $existing = ZatcaInvoice::query()->where('invoice_id', $invoiceRecord->id)->first();
        if ($existing && $existing->status === ZatcaInvoice::STATUS_REPORTED) {
            return $existing;
        }

        $invoice = $existing ?? new ZatcaInvoice([
            'reservation_id' => $reservationId,
            'invoice_id' => $invoiceRecord->id,
            'invoice_number' => 'HOT-R'.$reservationId.'-'.now()->format('YmdHis'),
            'uuid' => (string) Str::uuid(),
            'status' => ZatcaInvoice::STATUS_PENDING,
        ]);
        $invoice->invoice_id = $invoiceRecord->id;

        $credentials = $this->store->load($taxHotelId);
        if ($credentials === null) {
            $invoice->fill([
                'status' => ZatcaInvoice::STATUS_SKIPPED,
                'error_message' => 'ZATCA credentials missing. Run php artisan zatca:sandbox-onboard.',
            ]);
            $invoice->save();

            return $invoice;
        }

        $token = (string) data_get($credentials, 'production.binary_security_token', '');
        $secret = (string) data_get($credentials, 'production.secret', '');
        $privateKeyPath = (string) data_get($credentials, 'paths.private_key', '');
        if ($token === '' || $secret === '' || ! is_file($privateKeyPath)) {
            $invoice->fill([
                'status' => ZatcaInvoice::STATUS_SKIPPED,
                'error_message' => 'ZATCA production CSID/private key missing.',
            ]);
            $invoice->save();

            return $invoice;
        }

        $decodedCertificate = base64_decode($token, true);
        if ($decodedCertificate === false || $decodedCertificate === '') {
            throw new RuntimeException('Invalid production binarySecurityToken.');
        }

        $seller = $this->sellerFromCertificate($decodedCertificate, $taxHotelId);
        $icv = $this->nextIcv($taxHotelId);
        $previousHash = $this->latestInvoiceHash($taxHotelId);

        $guestName = $reservation->guestDisplayName();
        if ($guestName === '—') {
            $guestName = 'Hotel Guest';
        }

        $nights = max(1, (int) ($reservation->nights ?: 1));
        $lineName = 'Hotel stay #'.$reservationId;
        $rooms = $reservation->reservationRooms
            ->map(fn ($rr) => $rr->room?->room_number)
            ->filter()
            ->values();
        if ($rooms->isNotEmpty()) {
            $lineName .= ' / Room '.$rooms->implode(', ');
        }

        if (! $invoice->exists) {
            $invoice->save();
        }
        $invoice->icv = $icv;

        try {
            $unsignedXml = $this->buildXml(
                uuid: (string) $invoice->uuid,
                invoiceId: (string) $invoice->invoice_number,
                vat: $seller['vat'],
                orgName: $seller['name'],
                customerName: $guestName,
                lineName: $lineName,
                quantity: $nights,
                lineExtension: $subtotal,
                taxAmount: $tax,
                taxInclusive: $total,
                icv: $icv,
                previousInvoiceHash: $previousHash,
                note: 'Checkout invoice for reservation #'.$reservationId,
            );
        } catch (\Throwable $e) {
            $invoice->fill([
                'status' => ZatcaInvoice::STATUS_FAILED,
                'error_message' => $e->getMessage(),
            ]);
            $invoice->save();
            Log::warning('ZATCA checkout invoice build/sign failed', [
                'reservation_id' => $reservationId,
                'error' => $e->getMessage(),
            ]);

            return $invoice->fresh();
        }

        return $this->signAndSubmitInvoice($invoice, $unsignedXml, $privateKeyPath, $decodedCertificate, $secret, 'reservation_'.$reservationId);
    }

    /**
     * Reports a credit note against a reservation's already-reported tax invoice —
     * required whenever a refund is issued after checkout, since ZATCA has no
     * concept of silently amending a previously reported simplified invoice.
     * Returns null when there's nothing valid to report against yet (no prior
     * invoice, or that invoice was never actually accepted by ZATCA) rather than
     * throwing, since a failed/missing original isn't the caller's fault.
     */
    public function reportCreditNote(int $reservationId, float $refundAmount): ?ZatcaInvoice
    {
        if ($refundAmount <= 0.005) {
            return null;
        }

        $taxInvoice = InvoiceRecord::query()
            ->where('reservation_id', $reservationId)
            ->where('type', InvoiceRecord::TYPE_TAX_INVOICE)
            ->first();
        if ($taxInvoice === null) {
            return null;
        }

        $originalZatcaInvoice = ZatcaInvoice::query()->where('invoice_id', $taxInvoice->id)->first();
        if ($originalZatcaInvoice === null || $originalZatcaInvoice->status !== ZatcaInvoice::STATUS_REPORTED) {
            return null;
        }

        $total = round($refundAmount, 2);
        $tax = round($total - $total / 1.15, 2);
        $subtotal = round($total - $tax, 2);

        // Inherit the original invoice's hotel/tax-entity rather than
        // re-resolving from the reservation's current rooms — a credit note
        // must stay on the same tax entity/sequence its original invoice used
        // even if the reservation's rooms changed since.
        $hotelId = (int) $taxInvoice->hotel_id;
        $taxHotelId = (int) $taxInvoice->tax_hotel_id;

        $creditNoteRecord = InvoiceRecord::create([
            'reservation_id' => $reservationId,
            'related_invoice_id' => $taxInvoice->id,
            'hotel_id' => $hotelId,
            'tax_hotel_id' => $taxHotelId,
            'type' => InvoiceRecord::TYPE_CREDIT_NOTE,
            'status' => InvoiceRecord::STATUS_ISSUED,
            'subtotal' => $subtotal,
            'tax_amount' => $tax,
            'total' => $total,
            'currency' => 'SAR',
            'line_items' => [[
                'name' => 'Refund for hotel stay #'.$reservationId,
                'quantity' => 1,
                'unit_price' => $subtotal,
                'line_total' => $subtotal,
                'tax_amount' => $tax,
            ]],
            'issued_at' => now(),
        ]);
        $creditNoteRecord->invoice_number = $this->taxResolver->nextInvoiceNumber($taxHotelId, 'CN-');
        $creditNoteRecord->save();

        $credentials = $this->store->load($taxHotelId);
        $token = (string) data_get($credentials, 'production.binary_security_token', '');
        $secret = (string) data_get($credentials, 'production.secret', '');
        $privateKeyPath = (string) data_get($credentials, 'paths.private_key', '');
        $decodedCertificate = $token !== '' ? base64_decode($token, true) : false;

        $zatcaCreditNote = new ZatcaInvoice([
            'reservation_id' => $reservationId,
            'invoice_id' => $creditNoteRecord->id,
            'invoice_number' => 'HOT-CN'.$reservationId.'-'.now()->format('YmdHis'),
            'uuid' => (string) Str::uuid(),
            'status' => ZatcaInvoice::STATUS_PENDING,
        ]);

        if ($credentials === null || $secret === '' || ! is_file($privateKeyPath) || $decodedCertificate === false || $decodedCertificate === '') {
            $zatcaCreditNote->status = ZatcaInvoice::STATUS_SKIPPED;
            $zatcaCreditNote->error_message = 'ZATCA credentials missing. Run php artisan zatca:sandbox-onboard.';
            $zatcaCreditNote->save();

            return $zatcaCreditNote;
        }

        $reservation = Reservation::query()->findOrFail($reservationId);
        $seller = $this->sellerFromCertificate($decodedCertificate, $taxHotelId);
        $icv = $this->nextIcv($taxHotelId);
        $previousHash = $this->latestInvoiceHash($taxHotelId);
        $guestName = $reservation->guestDisplayName();
        if ($guestName === '—') {
            $guestName = 'Hotel Guest';
        }

        $zatcaCreditNote->icv = $icv;
        $zatcaCreditNote->save();

        try {
            $unsignedXml = $this->buildXml(
                uuid: (string) $zatcaCreditNote->uuid,
                invoiceId: (string) $zatcaCreditNote->invoice_number,
                vat: $seller['vat'],
                orgName: $seller['name'],
                customerName: $guestName,
                lineName: 'Refund for hotel stay #'.$reservationId,
                quantity: 1,
                lineExtension: $subtotal,
                taxAmount: $tax,
                taxInclusive: $total,
                icv: $icv,
                previousInvoiceHash: $previousHash,
                note: 'Credit note for reservation #'.$reservationId,
                invoiceTypeKind: 'credit',
                billingReferenceId: $originalZatcaInvoice->invoice_number,
                paymentMeansCode: '42',
                paymentMeansReason: 'Refund for reservation #'.$reservationId,
            );
        } catch (\Throwable $e) {
            $zatcaCreditNote->fill(['status' => ZatcaInvoice::STATUS_FAILED, 'error_message' => $e->getMessage()]);
            $zatcaCreditNote->save();
            Log::warning('ZATCA credit note build/sign failed', [
                'reservation_id' => $reservationId,
                'error' => $e->getMessage(),
            ]);

            return $zatcaCreditNote->fresh();
        }

        return $this->signAndSubmitInvoice($zatcaCreditNote, $unsignedXml, $privateKeyPath, $decodedCertificate, $secret, 'reservation_'.$reservationId.'_creditnote_'.$creditNoteRecord->id);
    }

    /**
     * ICV (invoice counter value) and PIH (previous invoice hash) are both
     * ZATCA's chain-of-custody fields for one certificate/reporting unit —
     * they must be scoped per tax entity, never counted globally, or two
     * branches with separate certificates would corrupt each other's chain.
     */
    private function reportedInvoicesForTaxHotel(int $taxHotelId)
    {
        return ZatcaInvoice::query()
            ->join('invoices', 'invoices.id', '=', 'zatca_invoices.invoice_id')
            ->where('invoices.tax_hotel_id', $taxHotelId)
            ->where('zatca_invoices.status', ZatcaInvoice::STATUS_REPORTED);
    }

    private function nextIcv(int $taxHotelId): int
    {
        return $this->reportedInvoicesForTaxHotel($taxHotelId)->count() + 1;
    }

    private function latestInvoiceHash(int $taxHotelId): string
    {
        return (string) (
            $this->reportedInvoicesForTaxHotel($taxHotelId)
                ->whereNotNull('zatca_invoices.invoice_hash')
                ->orderByDesc('zatca_invoices.id')
                ->value('zatca_invoices.invoice_hash')
            ?: self::FIRST_PIH
        );
    }

    private function signAndSubmitInvoice(
        ZatcaInvoice $zatcaInvoice,
        string $unsignedXml,
        string $privateKeyPath,
        string $decodedCertificate,
        string $secret,
        string $storageFileSuffix,
    ): ZatcaInvoice {
        try {
            $directory = (string) config('zatca.storage_path');
            if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
                throw new RuntimeException('Unable to create ZATCA storage directory.');
            }

            $privateKeyPem = (string) file_get_contents($privateKeyPath);
            $cleanPrivateKey = trim(str_replace(
                ['-----BEGIN EC PRIVATE KEY-----', '-----END EC PRIVATE KEY-----', '-----BEGIN PRIVATE KEY-----', '-----END PRIVATE KEY-----', "\r", "\n", ' '],
                '',
                $privateKeyPem
            ));

            $certificate = new Certificate($decodedCertificate, $cleanPrivateKey, $secret);
            $signed = InvoiceSigner::signInvoice($unsignedXml, $certificate);
            $signedXml = $signed->getXML();
            $invoiceHash = $signed->getHash();
            $qrCode = trim((string) $signed->getQRCode());

            $signedPath = $directory.DIRECTORY_SEPARATOR.$storageFileSuffix.'_signed.xml';
            file_put_contents($signedPath, $signedXml);

            $env = (string) config('zatca.environment', 'sandbox');
            $api = new ZatcaAPI($env === 'production' ? 'production' : ($env === 'simulation' ? 'simulation' : 'sandbox'));

            try {
                $response = $api->submitReportingInvoice($decodedCertificate, $secret, $signedXml, $invoiceHash, (string) $zatcaInvoice->uuid);
                $zatcaInvoice->fill([
                    'invoice_hash' => $invoiceHash,
                    'qr_code' => $qrCode !== '' ? $qrCode : null,
                    'signed_xml_path' => $signedPath,
                    'http_status' => $response->getStatusCode(),
                    'reporting_status' => $response->getReportingStatus(),
                    'response_payload' => $response->toArray(),
                    'status' => $response->isReported() || $response->getStatusCode() === 200
                        ? ZatcaInvoice::STATUS_REPORTED
                        : ZatcaInvoice::STATUS_FAILED,
                    'error_message' => $response->isReported() || $response->getStatusCode() === 200
                        ? null
                        : 'Reporting status: '.($response->getReportingStatus() ?? 'unknown'),
                    'reported_at' => now(),
                ]);
            } catch (\Throwable $e) {
                $context = method_exists($e, 'getContext') ? $e->getContext() : [];
                $zatcaInvoice->fill([
                    'invoice_hash' => $invoiceHash,
                    'qr_code' => $qrCode !== '' ? $qrCode : null,
                    'signed_xml_path' => $signedPath,
                    'http_status' => isset($context['statusCode']) ? (int) $context['statusCode'] : null,
                    'response_payload' => is_array($context['response'] ?? null) ? $context['response'] : $context,
                    'status' => ZatcaInvoice::STATUS_FAILED,
                    'error_message' => $e->getMessage(),
                ]);
                Log::warning('ZATCA report failed', ['error' => $e->getMessage()]);
            }
        } catch (\Throwable $e) {
            $zatcaInvoice->fill([
                'status' => ZatcaInvoice::STATUS_FAILED,
                'error_message' => $e->getMessage(),
            ]);
            Log::warning('ZATCA invoice build/sign failed', ['error' => $e->getMessage()]);
        }

        $zatcaInvoice->save();

        return $zatcaInvoice->fresh();
    }

    /**
     * Public summary for reservation detail / invoice print.
     *
     * @return array{invoice_number: string|null, uuid: string|null, status: string|null, reporting_status: string|null, qr_code: string|null}|null
     */
    public function invoiceSummaryForReservation(int $reservationId): ?array
    {
        try {
            if (! \Illuminate\Support\Facades\Schema::hasTable('zatca_invoices')) {
                return null;
            }

            $taxInvoiceId = InvoiceRecord::query()
                ->where('reservation_id', $reservationId)
                ->where('type', InvoiceRecord::TYPE_TAX_INVOICE)
                ->value('id');

            $invoice = $taxInvoiceId
                ? ZatcaInvoice::query()->where('invoice_id', $taxInvoiceId)->first()
                : ZatcaInvoice::query()->where('reservation_id', $reservationId)->whereNull('invoice_id')->first();
            if ($invoice === null) {
                return null;
            }

            if ($invoice->status === ZatcaInvoice::STATUS_PENDING && $this->shouldReportOnCheckout()) {
                try {
                    $invoice = $this->reportReservationCheckout($reservationId);
                } catch (\Throwable $e) {
                    Log::warning('ZATCA pending invoice retry failed', [
                        'reservation_id' => $reservationId,
                        'error' => $e->getMessage(),
                    ]);
                    $invoice->refresh();
                }
            }

            $qr = trim((string) ($invoice->qr_code ?? ''));
            if ($qr === '' && \Illuminate\Support\Facades\Schema::hasColumn('zatca_invoices', 'qr_code')) {
                $qr = $this->extractQrFromSignedXml((string) ($invoice->signed_xml_path ?? ''));
                if ($qr !== '') {
                    $invoice->qr_code = $qr;
                    $invoice->save();
                }
            }

            $businessInvoice = $invoice->invoice_id
                ? InvoiceRecord::query()->find($invoice->invoice_id)
                : InvoiceRecord::query()->where('reservation_id', $reservationId)->where('type', InvoiceRecord::TYPE_TAX_INVOICE)->first();

            $creditNotes = $businessInvoice
                ? InvoiceRecord::query()->where('related_invoice_id', $businessInvoice->id)->orderBy('id')->get()
                    ->map(fn (InvoiceRecord $cn) => [
                        'invoice_number' => $cn->invoice_number,
                        'total' => $cn->total,
                        'issued_at' => optional($cn->issued_at)->toIso8601String(),
                    ])
                : collect();

            return [
                // Kept for backward compatibility — this is the technical ZATCA XML ID,
                // not the guest-facing invoice number (see 'invoice' below for that).
                'invoice_number' => $invoice->invoice_number,
                'uuid' => $invoice->uuid,
                'status' => $invoice->status,
                'reporting_status' => $invoice->reporting_status,
                'qr_code' => $qr !== '' ? $qr : null,
                'invoice' => $businessInvoice ? [
                    'invoice_number' => $businessInvoice->invoice_number,
                    'subtotal' => $businessInvoice->subtotal,
                    'tax_amount' => $businessInvoice->tax_amount,
                    'total' => $businessInvoice->total,
                    'currency' => $businessInvoice->currency,
                    'issued_at' => optional($businessInvoice->issued_at)->toIso8601String(),
                ] : null,
                'credit_notes' => $creditNotes,
            ];
        } catch (\Throwable $e) {
            Log::warning('ZATCA invoice summary unavailable', [
                'reservation_id' => $reservationId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function extractQrFromSignedXml(string $signedXmlPath): string
    {
        if ($signedXmlPath === '' || ! is_file($signedXmlPath)) {
            return '';
        }

        $xml = (string) file_get_contents($signedXmlPath);
        if ($xml === '') {
            return '';
        }

        if (preg_match(
            '/<cbc:ID>\s*QR\s*<\/cbc:ID>[\s\S]*?<cbc:EmbeddedDocumentBinaryObject[^>]*>([^<]+)<\/cbc:EmbeddedDocumentBinaryObject>/i',
            $xml,
            $matches
        ) === 1) {
            return trim($matches[1]);
        }

        return '';
    }

    /**
     * @return array{vat: string, name: string}
     */
    private function sellerFromCertificate(string $decodedCertificate, int $taxHotelId): array
    {
        $pem = str_contains($decodedCertificate, 'BEGIN CERTIFICATE')
            ? $decodedCertificate
            : "-----BEGIN CERTIFICATE-----\n".chunk_split(trim($decodedCertificate), 64, "\n")."-----END CERTIFICATE-----\n";

        $parsed = openssl_x509_parse($pem);
        $cn = is_array($parsed) ? (string) ($parsed['subject']['CN'] ?? '') : '';
        $org = is_array($parsed) ? (string) ($parsed['subject']['O'] ?? '') : '';

        $csrConfig = $this->taxResolver->csrConfig($taxHotelId);

        $vat = (string) $csrConfig['organization_identifier'];
        if (preg_match('/(\d{15})$/', $cn, $matches) === 1) {
            $vat = $matches[1];
        }

        return [
            'vat' => $vat,
            'name' => $org !== '' ? $org : (string) ($csrConfig['organization_name'] ?? 'Hotel System Test'),
        ];
    }

    private function buildXml(
        string $uuid,
        string $invoiceId,
        string $vat,
        string $orgName,
        string $customerName,
        string $lineName,
        int $quantity,
        float $lineExtension,
        float $taxAmount,
        float $taxInclusive,
        int $icv,
        string $previousInvoiceHash,
        string $note,
        string $invoiceTypeKind = 'invoice',
        ?string $billingReferenceId = null,
        string $paymentMeansCode = '10',
        ?string $paymentMeansReason = null,
    ): string {
        $now = new DateTime('now');
        $unitPrice = round($lineExtension / max(1, $quantity), 2);

        $signatureInfo = (new SignatureInformation)
            ->setReferencedSignatureID('urn:oasis:names:specification:ubl:signature:Invoice')
            ->setID('urn:oasis:names:specification:ubl:signature:1');

        $ublExtensions = (new UBLExtensions)->setUBLExtensions([
            (new UBLExtension)
                ->setExtensionURI('urn:oasis:names:specification:ubl:dsig:enveloped:xades')
                ->setExtensionContent(
                    (new ExtensionContent)->setUBLDocumentSignatures(
                        (new UBLDocumentSignatures)->setSignatureInformation($signatureInfo)
                    )
                ),
        ]);

        $signature = (new Signature)
            ->setId('urn:oasis:names:specification:ubl:signature:Invoice')
            ->setSignatureMethod('urn:oasis:names:specification:ubl:dsig:enveloped:xades');

        $invoiceType = (new InvoiceType)
            ->setInvoice('simplified')
            ->setInvoiceType($invoiceTypeKind)
            ->setIsThirdParty(false)
            ->setIsNominal(false)
            ->setIsExportInvoice(false)
            ->setIsSummary(false)
            ->setIsSelfBilled(false);

        $pihAttachment = (new Attachment)->setBase64Content($previousInvoiceHash, 'base64', 'text/plain');
        $additionalDocs = [
            (new AdditionalDocumentReference)->setId('ICV')->setUUID((string) $icv),
            (new AdditionalDocumentReference)->setId('PIH')->setAttachment($pihAttachment),
            (new AdditionalDocumentReference)->setId('QR'),
        ];

        $taxScheme = (new TaxScheme)->setId('VAT');
        $taxCategory = (new TaxCategory)->setPercent(15)->setTaxScheme($taxScheme);

        $supplier = (new Party)
            ->setPartyIdentification('1010010000')
            ->setPartyIdentificationId('CRN')
            ->setLegalEntity((new LegalEntity)->setRegistrationName($orgName))
            ->setPartyTaxScheme((new PartyTaxScheme)->setTaxScheme($taxScheme)->setCompanyId($vat))
            ->setPostalAddress(
                (new Address)
                    ->setStreetName('Prince Sultan')
                    ->setBuildingNumber('2322')
                    ->setCitySubdivisionName('Al-Murabba')
                    ->setCityName('Riyadh')
                    ->setPostalZone('23333')
                    ->setCountry('SA')
            );

        $customer = (new Party)
            ->setLegalEntity((new LegalEntity)->setRegistrationName($customerName))
            ->setPartyTaxScheme((new PartyTaxScheme)->setTaxScheme($taxScheme))
            ->setPostalAddress(
                (new Address)
                    ->setStreetName('Guest Street')
                    ->setBuildingNumber('1111')
                    ->setCitySubdivisionName('Al-Murooj')
                    ->setCityName('Riyadh')
                    ->setPostalZone('12222')
                    ->setCountry('SA')
            );

        $allowanceCharges = [
            (new AllowanceCharge)
                ->setChargeIndicator(false)
                ->setAllowanceChargeReason('discount')
                ->setAmount(0.00)
                ->setTaxCategory([$taxCategory]),
        ];

        $taxTotal = (new TaxTotal)
            ->addTaxSubTotal(
                (new TaxSubTotal)
                    ->setTaxableAmount($lineExtension)
                    ->setTaxAmount($taxAmount)
                    ->setTaxCategory($taxCategory)
            )
            ->setTaxAmount($taxAmount);

        $legalMonetaryTotal = (new LegalMonetaryTotal)
            ->setLineExtensionAmount($lineExtension)
            ->setTaxExclusiveAmount($lineExtension)
            ->setTaxInclusiveAmount($taxInclusive)
            ->setPrepaidAmount(0)
            ->setPayableAmount($taxInclusive)
            ->setAllowanceTotalAmount(0);

        $invoiceLine = (new InvoiceLine)
            ->setUnitCode('PCE')
            ->setId(1)
            ->setItem(
                (new Item)
                    ->setName($lineName)
                    ->setClassifiedTaxCategory(
                        (new ClassifiedTaxCategory)->setPercent(15)->setTaxScheme($taxScheme)
                    )
            )
            ->setLineExtensionAmount($lineExtension)
            ->setPrice(
                (new Price)
                    ->setUnitCode(UnitCode::UNIT)
                    ->setAllowanceCharges([
                        (new AllowanceCharge)
                            ->setChargeIndicator(false)
                            ->setAllowanceChargeReason('discount')
                            ->setAmount(0.00),
                    ])
                    ->setPriceAmount($unitPrice)
            )
            ->setTaxTotal((new TaxTotal)->setTaxAmount($taxAmount)->setRoundingAmount($taxInclusive))
            ->setInvoicedQuantity($quantity);

        $invoice = (new Invoice)
            ->setUBLExtensions($ublExtensions)
            ->setUUID($uuid)
            ->setId($invoiceId)
            ->setIssueDate($now)
            ->setIssueTime($now)
            ->setInvoiceType($invoiceType)
            ->setNote($note)
            ->setlanguageID('en')
            ->setInvoiceCurrencyCode('SAR')
            ->setTaxCurrencyCode('SAR')
            ->setAdditionalDocumentReferences($additionalDocs)
            ->setAccountingSupplierParty($supplier)
            ->setAccountingCustomerParty($customer)
            ->setPaymentMeans((new PaymentMeans)->setPaymentMeansCode($paymentMeansCode)->setInstructionNote($paymentMeansReason))
            ->setAllowanceCharges($allowanceCharges)
            ->setTaxTotal($taxTotal)
            ->setLegalMonetaryTotal($legalMonetaryTotal)
            ->setInvoiceLines([$invoiceLine])
            ->setSignature($signature);

        if ($billingReferenceId !== null) {
            $invoice->setBillingReferences([(new BillingReference)->setId($billingReferenceId)]);
        }

        return GeneratorInvoice::invoice($invoice)->getXML();
    }
}
