<?php

namespace App\Services\Zatca;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class ZatcaSandboxClient
{
    public function baseUrl(): string
    {
        $env = (string) config('zatca.environment', 'sandbox');
        $urls = config('zatca.base_urls', []);

        return rtrim((string) ($urls[$env] ?? $urls['sandbox']), '/');
    }

    /**
     * @return array{requestID: mixed, dispositionMessage?: string, binarySecurityToken: string, secret: string, raw: array}
     */
    public function requestComplianceCsid(string $csrBase64, ?string $otp = null): array
    {
        $otp = $otp ?: (string) config('zatca.otp');

        $response = $this->http()
            ->withHeaders([
                'OTP' => $otp,
                'Accept-Version' => config('zatca.accept_version', 'V2'),
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ])
            ->post($this->baseUrl().'/compliance', [
                'csr' => $csrBase64,
            ]);

        return $this->parseCsidResponse($response, 'Compliance CSID');
    }

    /**
     * @return array{requestID?: mixed, dispositionMessage?: string, binarySecurityToken: string, secret: string, raw: array}
     */
    public function requestProductionCsid(string $complianceRequestId, string $binarySecurityToken, string $secret): array
    {
        $response = $this->http()
            ->withHeaders([
                'Accept-Version' => config('zatca.accept_version', 'V2'),
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ])
            ->withBasicAuth($binarySecurityToken, $secret)
            ->post($this->baseUrl().'/production/csids', [
                'compliance_request_id' => $complianceRequestId,
            ]);

        return $this->parseCsidResponse($response, 'Production CSID');
    }

    private function http(): PendingRequest
    {
        return Http::timeout(60)->retry(1, 500);
    }

    /**
     * @return array{requestID?: mixed, dispositionMessage?: string, binarySecurityToken: string, secret: string, raw: array}
     */
    private function parseCsidResponse(Response $response, string $label): array
    {
        $json = $response->json();
        if (! is_array($json)) {
            $json = [];
        }

        if (! $response->successful()) {
            $message = $json['message']
                ?? (isset($json['errors'][0]['message']) ? $json['errors'][0]['message'] : null)
                ?? $response->body();

            throw new RuntimeException("{$label} failed ({$response->status()}): {$message}");
        }

        $token = (string) ($json['binarySecurityToken'] ?? '');
        $secret = (string) ($json['secret'] ?? '');
        if ($token === '' || $secret === '') {
            throw new RuntimeException("{$label} response missing binarySecurityToken/secret.");
        }

        return [
            'requestID' => $json['requestID'] ?? null,
            'dispositionMessage' => $json['dispositionMessage'] ?? null,
            'binarySecurityToken' => $token,
            'secret' => $secret,
            'raw' => $json,
        ];
    }
}
