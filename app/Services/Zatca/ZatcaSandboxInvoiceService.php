<?php

namespace App\Services\Zatca;

use DateTime;
use Illuminate\Support\Str;
use RuntimeException;
use Saleh7\Zatca\AdditionalDocumentReference;
use Saleh7\Zatca\Address;
use Saleh7\Zatca\AllowanceCharge;
use Saleh7\Zatca\Attachment;
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

class ZatcaSandboxInvoiceService
{
    public function __construct(private ZatcaCredentialStore $store) {}

    /**
     * Build, sign, and report one simplified sandbox invoice.
     *
     * @return array{
     *     uuid: string,
     *     invoice_id: string,
     *     invoice_hash: string,
     *     signed_xml_path: string,
     *     postman_payload_path: string,
     *     http_status: int|null,
     *     reporting_status: string|null,
     *     raw: array<string, mixed>|null
     * }
     */
    public function reportSimplifiedTestInvoice(): array
    {
        $credentials = $this->store->load();
        if ($credentials === null) {
            throw new RuntimeException('No ZATCA credentials. Run: php artisan zatca:sandbox-onboard');
        }

        $token = (string) data_get($credentials, 'production.binary_security_token', '');
        $secret = (string) data_get($credentials, 'production.secret', '');
        $privateKeyPath = (string) data_get($credentials, 'paths.private_key', '');
        if ($token === '' || $secret === '' || $privateKeyPath === '' || ! is_file($privateKeyPath)) {
            throw new RuntimeException('Production CSID/private key missing. Re-run zatca:sandbox-onboard.');
        }

        // saleh7/php-zatca-xml stores/uses base64_decode(binarySecurityToken) as the certificate string.
        $decodedCertificate = base64_decode($token, true);
        if ($decodedCertificate === false || $decodedCertificate === '') {
            throw new RuntimeException('Invalid production binarySecurityToken (base64 decode failed).');
        }

        // Sandbox certificates often embed the allowed VAT in CN (e.g. TST-...-399999999900003).
        $seller = $this->sellerFromCertificate($decodedCertificate);
        $vat = $seller['vat'];
        $orgName = $seller['name'];

        $uuid = (string) Str::uuid();
        $invoiceId = 'HOT-SB-'.now()->format('YmdHis');
        $directory = (string) config('zatca.storage_path');
        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException('Unable to create ZATCA storage directory.');
        }

        $unsignedXml = $this->buildSimplifiedInvoiceXml($uuid, $invoiceId, $vat, $orgName);
        $unsignedPath = $directory.DIRECTORY_SEPARATOR.'simplified_unsigned.xml';
        file_put_contents($unsignedPath, $unsignedXml);

        $privateKeyPem = (string) file_get_contents($privateKeyPath);
        $cleanPrivateKey = trim(str_replace(
            ['-----BEGIN EC PRIVATE KEY-----', '-----END EC PRIVATE KEY-----', '-----BEGIN PRIVATE KEY-----', '-----END PRIVATE KEY-----', "\r", "\n", ' '],
            '',
            $privateKeyPem
        ));

        try {
            $certificate = new Certificate($decodedCertificate, $cleanPrivateKey, $secret);
            $signed = InvoiceSigner::signInvoice($unsignedXml, $certificate);
        } catch (\Throwable $e) {
            throw new RuntimeException('Failed to sign invoice: '.$e->getMessage(), 0, $e);
        }

        $signedXml = $signed->getXML();
        $invoiceHash = $signed->getHash();

        $signedPath = $directory.DIRECTORY_SEPARATOR.'simplified_signed.xml';
        file_put_contents($signedPath, $signedXml);

        $postmanPayload = [
            'url' => rtrim((string) config('zatca.base_urls.sandbox'), '/').'/invoices/reporting/single',
            'method' => 'POST',
            'headers' => [
                'Accept-Version' => 'V2',
                'Accept-Language' => 'en',
                'Clearance-Status' => '0',
                'Content-Type' => 'application/json',
            ],
            'auth' => [
                'type' => 'basic',
                'username' => $token,
                'password' => $secret,
            ],
            'body' => [
                'invoiceHash' => $invoiceHash,
                'uuid' => $uuid,
                'invoice' => base64_encode($signedXml),
            ],
        ];
        $postmanPath = $directory.DIRECTORY_SEPARATOR.'postman_reporting_payload.json';
        file_put_contents($postmanPath, json_encode($postmanPayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $httpStatus = null;
        $reportingStatus = null;
        $raw = null;
        $error = null;

        try {
            $api = new ZatcaAPI('sandbox');
            // Library auth expects decoded certificate (it re-base64-encodes internally).
            $response = $api->submitReportingInvoice($decodedCertificate, $secret, $signedXml, $invoiceHash, $uuid);
            $httpStatus = $response->getStatusCode();
            $reportingStatus = $response->getReportingStatus();
            $raw = $response->toArray();
        } catch (\Throwable $e) {
            $error = $e->getMessage();
            if (method_exists($e, 'getContext')) {
                /** @var array<string, mixed> $context */
                $context = $e->getContext();
                $raw = is_array($context['response'] ?? null) ? $context['response'] : $context;
                $httpStatus = isset($context['statusCode']) ? (int) $context['statusCode'] : $httpStatus;
            }
        }

        return [
            'uuid' => $uuid,
            'invoice_id' => $invoiceId,
            'invoice_hash' => $invoiceHash,
            'signed_xml_path' => $signedPath,
            'postman_payload_path' => $postmanPath,
            'http_status' => $httpStatus,
            'reporting_status' => $reportingStatus,
            'raw' => $raw,
            'error' => $error,
        ];
    }

    /**
     * @return array{vat: string, name: string}
     */
    private function sellerFromCertificate(string $decodedCertificate): array
    {
        $pem = str_contains($decodedCertificate, 'BEGIN CERTIFICATE')
            ? $decodedCertificate
            : "-----BEGIN CERTIFICATE-----\n".chunk_split(trim($decodedCertificate), 64, "\n")."-----END CERTIFICATE-----\n";

        $parsed = openssl_x509_parse($pem);
        $cn = is_array($parsed) ? (string) ($parsed['subject']['CN'] ?? '') : '';
        $org = is_array($parsed) ? (string) ($parsed['subject']['O'] ?? '') : '';

        $vat = (string) config('zatca.csr.organization_identifier');
        if (preg_match('/(\d{15})$/', $cn, $matches) === 1) {
            $vat = $matches[1];
        }

        return [
            'vat' => $vat,
            'name' => $org !== '' ? $org : (string) config('zatca.csr.organization_name', 'Hotel System Test'),
        ];
    }

    private function buildSimplifiedInvoiceXml(string $uuid, string $invoiceId, string $vat, string $orgName): string
    {
        $now = new DateTime('now');

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
            ->setInvoiceType('invoice')
            ->setIsThirdParty(false)
            ->setIsNominal(false)
            ->setIsExportInvoice(false)
            ->setIsSummary(false)
            ->setIsSelfBilled(false);

        // First-invoice PIH used in ZATCA samples (hash of "0").
        $pihAttachment = (new Attachment)->setBase64Content(
            'NWZlY2ViNjZmZmM4NmYzOGQ5NTI3ODZjNmQ2OTZjNzljMmRiYzIzOWRkNGU5MWI0NjcyOWQ3M2EyN2ZiNTdlOQ==',
            'base64',
            'text/plain'
        );

        $additionalDocs = [
            (new AdditionalDocumentReference)->setId('ICV')->setUUID('1'),
            (new AdditionalDocumentReference)->setId('PIH')->setAttachment($pihAttachment),
            (new AdditionalDocumentReference)->setId('QR'),
        ];

        $taxScheme = (new TaxScheme)->setId('VAT');

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
            ->setLegalEntity((new LegalEntity)->setRegistrationName('Walk-in Guest'))
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

        $taxCategory = (new TaxCategory)->setPercent(15)->setTaxScheme($taxScheme);
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
                    ->setTaxableAmount(100)
                    ->setTaxAmount(15)
                    ->setTaxCategory($taxCategory)
            )
            ->setTaxAmount(15);

        $legalMonetaryTotal = (new LegalMonetaryTotal)
            ->setLineExtensionAmount(100)
            ->setTaxExclusiveAmount(100)
            ->setTaxInclusiveAmount(115)
            ->setPrepaidAmount(0)
            ->setPayableAmount(115)
            ->setAllowanceTotalAmount(0);

        $invoiceLine = (new InvoiceLine)
            ->setUnitCode('PCE')
            ->setId(1)
            ->setItem(
                (new Item)
                    ->setName('Hotel Room Night')
                    ->setClassifiedTaxCategory(
                        (new ClassifiedTaxCategory)->setPercent(15)->setTaxScheme($taxScheme)
                    )
            )
            ->setLineExtensionAmount(100)
            ->setPrice(
                (new Price)
                    ->setUnitCode(UnitCode::UNIT)
                    ->setAllowanceCharges([
                        (new AllowanceCharge)
                            ->setChargeIndicator(false)
                            ->setAllowanceChargeReason('discount')
                            ->setAmount(0.00),
                    ])
                    ->setPriceAmount(100)
            )
            ->setTaxTotal((new TaxTotal)->setTaxAmount(15)->setRoundingAmount(115))
            ->setInvoicedQuantity(1);

        $invoice = (new Invoice)
            ->setUBLExtensions($ublExtensions)
            ->setUUID($uuid)
            ->setId($invoiceId)
            ->setIssueDate($now)
            ->setIssueTime($now)
            ->setInvoiceType($invoiceType)
            ->setNote('Sandbox test invoice')
            ->setlanguageID('en')
            ->setInvoiceCurrencyCode('SAR')
            ->setTaxCurrencyCode('SAR')
            ->setAdditionalDocumentReferences($additionalDocs)
            ->setAccountingSupplierParty($supplier)
            ->setAccountingCustomerParty($customer)
            ->setPaymentMeans((new PaymentMeans)->setPaymentMeansCode('10'))
            ->setAllowanceCharges($allowanceCharges)
            ->setTaxTotal($taxTotal)
            ->setLegalMonetaryTotal($legalMonetaryTotal)
            ->setInvoiceLines([$invoiceLine])
            ->setSignature($signature);

        return GeneratorInvoice::invoice($invoice)->getXML();
    }
}
