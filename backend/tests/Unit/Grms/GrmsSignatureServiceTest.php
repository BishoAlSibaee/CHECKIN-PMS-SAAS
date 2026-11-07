<?php

namespace Tests\Unit\Grms;

use App\Services\Grms\GrmsSignatureService;
use Illuminate\Http\Request;
use Tests\TestCase;

class GrmsSignatureServiceTest extends TestCase
{
    private function requestWithHeaders(string $body, array $headers): Request
    {
        $request = Request::create('/api/grms/v1/events', 'POST', [], [], [], [], $body);
        foreach ($headers as $key => $value) {
            $request->headers->set($key, $value);
        }

        return $request;
    }

    public function test_sign_and_verify_round_trip(): void
    {
        $service = new GrmsSignatureService();
        $secret = 'a-test-secret';
        $body = '{"events":[]}';
        $timestamp = (string) time();

        $signature = $service->sign($timestamp, $body, $secret);

        $request = $this->requestWithHeaders($body, [
            'X-Timestamp' => $timestamp,
            'X-Signature' => $signature,
        ]);

        $result = $service->verify($request, $secret);

        $this->assertTrue($result['ok']);
        $this->assertNull($result['reason']);
    }

    public function test_wrong_secret_produces_a_different_signature(): void
    {
        $service = new GrmsSignatureService();
        $timestamp = (string) time();
        $body = '{"events":[]}';

        $signature = $service->sign($timestamp, $body, 'secret-a');
        $request = $this->requestWithHeaders($body, [
            'X-Timestamp' => $timestamp,
            'X-Signature' => $signature,
        ]);

        $result = $service->verify($request, 'secret-b');

        $this->assertFalse($result['ok']);
        $this->assertSame('invalid_signature', $result['reason']);
    }

    public function test_a_tampered_body_fails_verification(): void
    {
        $service = new GrmsSignatureService();
        $secret = 'a-test-secret';
        $timestamp = (string) time();

        $signature = $service->sign($timestamp, '{"events":[]}', $secret);
        $request = $this->requestWithHeaders('{"events":["tampered"]}', [
            'X-Timestamp' => $timestamp,
            'X-Signature' => $signature,
        ]);

        $result = $service->verify($request, $secret);

        $this->assertFalse($result['ok']);
        $this->assertSame('invalid_signature', $result['reason']);
    }

    public function test_a_timestamp_outside_the_tolerance_window_is_rejected(): void
    {
        $service = new GrmsSignatureService();
        $secret = 'a-test-secret';
        $body = '{"events":[]}';
        $staleTimestamp = (string) (time() - 600);

        $signature = $service->sign($staleTimestamp, $body, $secret);
        $request = $this->requestWithHeaders($body, [
            'X-Timestamp' => $staleTimestamp,
            'X-Signature' => $signature,
        ]);

        $result = $service->verify($request, $secret);

        $this->assertFalse($result['ok']);
        $this->assertSame('timestamp_out_of_range', $result['reason']);
    }

    public function test_missing_headers_are_rejected(): void
    {
        $service = new GrmsSignatureService();
        $request = $this->requestWithHeaders('{"events":[]}', []);

        $result = $service->verify($request, 'any-secret');

        $this->assertFalse($result['ok']);
        $this->assertSame('missing_headers', $result['reason']);
    }
}
