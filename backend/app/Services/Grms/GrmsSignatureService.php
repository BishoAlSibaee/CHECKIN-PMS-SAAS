<?php

namespace App\Services\Grms;

use Illuminate\Http\Request;

/**
 * HMAC-SHA256 request signing for the GRMS v1 contract (replaces the legacy
 * integration's plain X-Grms-Webhook-Secret header comparison — see
 * GrmsWebhookController). Signature = hex(HMAC_SHA256(secret, timestamp.
 * "." .raw_body)). Secrets are per-hotel, per-direction — see
 * GrmsCredentialService/GrmsHotelCredential.
 */
class GrmsSignatureService
{
    private const MAX_CLOCK_SKEW_SECONDS = 300;

    public function sign(string $timestamp, string $rawBody, string $secret): string
    {
        return hash_hmac('sha256', $timestamp.'.'.$rawBody, $secret);
    }

    /**
     * @return array{ok: bool, reason: ?string} reason is one of
     *   'missing_headers'|'timestamp_out_of_range'|'invalid_signature' when ok is false.
     */
    public function verify(Request $request, string $secret): array
    {
        $timestamp = $request->header('X-Timestamp');
        $signature = $request->header('X-Signature');

        if (!is_string($timestamp) || $timestamp === '' || !is_string($signature) || $signature === '') {
            return ['ok' => false, 'reason' => 'missing_headers'];
        }

        if (!ctype_digit($timestamp) || abs(time() - (int) $timestamp) > self::MAX_CLOCK_SKEW_SECONDS) {
            return ['ok' => false, 'reason' => 'timestamp_out_of_range'];
        }

        $expected = $this->sign($timestamp, $request->getContent(), $secret);

        if (!hash_equals($expected, $signature)) {
            return ['ok' => false, 'reason' => 'invalid_signature'];
        }

        return ['ok' => true, 'reason' => null];
    }
}
