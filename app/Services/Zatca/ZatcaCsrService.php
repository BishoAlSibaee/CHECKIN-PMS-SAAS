<?php

namespace App\Services\Zatca;

use RuntimeException;

class ZatcaCsrService
{
    /**
     * Generate EC secp256k1 key + ZATCA CSR, persist under storage/app/zatca.
     *
     * @return array{private_key_path: string, csr_path: string, csr_base64: string, config: array<string, string>}
     */
    public function generateAndStore(?string $directory = null): array
    {
        $directory = $directory ?: (string) config('zatca.storage_path');
        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException("Unable to create ZATCA storage directory: {$directory}");
        }

        $csrConfig = config('zatca.csr');
        $opensslConfigPath = $directory.DIRECTORY_SEPARATOR.'openssl.cnf';
        file_put_contents($opensslConfigPath, $this->buildOpenSslConfig($csrConfig));

        $privateKey = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name' => 'secp256k1',
            'config' => $opensslConfigPath,
        ]);

        if ($privateKey === false) {
            throw new RuntimeException('Failed to generate EC private key (secp256k1): '.$this->opensslError());
        }

        $dn = [
            'C' => $csrConfig['country_name'],
            'OU' => $csrConfig['organization_unit_name'],
            'O' => $csrConfig['organization_name'],
            'CN' => $csrConfig['common_name'],
        ];

        $csr = openssl_csr_new($dn, $privateKey, [
            'digest_alg' => 'sha256',
            'req_extensions' => 'req_ext',
            'config' => $opensslConfigPath,
        ]);

        if ($csr === false) {
            throw new RuntimeException('Failed to generate CSR: '.$this->opensslError());
        }

        if (! openssl_pkey_export($privateKey, $privateKeyPem, null, ['config' => $opensslConfigPath])) {
            throw new RuntimeException('Failed to export private key: '.$this->opensslError());
        }

        if (! openssl_csr_export($csr, $csrPem)) {
            throw new RuntimeException('Failed to export CSR: '.$this->opensslError());
        }

        $privateKeyPath = $directory.DIRECTORY_SEPARATOR.'private_key.pem';
        $csrPath = $directory.DIRECTORY_SEPARATOR.'csr.pem';
        file_put_contents($privateKeyPath, $privateKeyPem);
        file_put_contents($csrPath, $csrPem);
        @chmod($privateKeyPath, 0600);

        return [
            'private_key_path' => $privateKeyPath,
            'csr_path' => $csrPath,
            'csr_base64' => base64_encode($csrPem),
            'config' => $csrConfig,
        ];
    }

    /**
     * @param  array<string, string>  $csrConfig
     */
    private function buildOpenSslConfig(array $csrConfig): string
    {
        $template = $csrConfig['certificate_template'];
        $serial = $csrConfig['serial_number'];
        $vat = $csrConfig['organization_identifier'];
        $invoiceType = $csrConfig['invoice_type'];
        $location = $csrConfig['location'];
        $industry = $csrConfig['industry'];
        $cn = $csrConfig['common_name'];
        $ou = $csrConfig['organization_unit_name'];
        $o = $csrConfig['organization_name'];
        $c = $csrConfig['country_name'];

        return <<<CNF
oid_section = OIDs
[OIDs]
certificateTemplateName = 1.3.6.1.4.1.311.20.2

[req]
default_bits = 2048
prompt = no
default_md = sha256
req_extensions = req_ext
distinguished_name = dn

[dn]
C={$c}
OU={$ou}
O={$o}
CN={$cn}

[req_ext]
certificateTemplateName = ASN1:PRINTABLESTRING:{$template}
subjectAltName = dirName:alt_names

[alt_names]
SN={$serial}
UID={$vat}
title={$invoiceType}
registeredAddress={$location}
businessCategory={$industry}
CNF;
    }

    private function opensslError(): string
    {
        $messages = [];
        while ($message = openssl_error_string()) {
            $messages[] = $message;
        }

        return $messages !== [] ? implode('; ', $messages) : 'unknown OpenSSL error';
    }
}
