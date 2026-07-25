<?php

namespace Tests\Unit\Zatca;

use App\Services\Zatca\ZatcaSandboxClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ZatcaSandboxClientTest extends TestCase
{
    public function test_request_compliance_csid_parses_success_payload(): void
    {
        config([
            'zatca.environment' => 'sandbox',
            'zatca.base_urls.sandbox' => 'https://zatca.test/e-invoicing/developer-portal',
            'zatca.otp' => '123345',
            'zatca.accept_version' => 'V2',
        ]);

        Http::fake([
            'https://zatca.test/e-invoicing/developer-portal/compliance' => Http::response([
                'requestID' => 1234567890123,
                'dispositionMessage' => 'ISSUED',
                'binarySecurityToken' => 'token-abc',
                'secret' => 'secret-xyz',
            ], 200),
        ]);

        $result = app(ZatcaSandboxClient::class)->requestComplianceCsid('YmFzZTY0LWNzcg==');

        $this->assertSame(1234567890123, $result['requestID']);
        $this->assertSame('token-abc', $result['binarySecurityToken']);
        $this->assertSame('secret-xyz', $result['secret']);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://zatca.test/e-invoicing/developer-portal/compliance'
                && $request->hasHeader('OTP', '123345')
                && $request->hasHeader('Accept-Version', 'V2')
                && $request['csr'] === 'YmFzZTY0LWNzcg==';
        });
    }
}
