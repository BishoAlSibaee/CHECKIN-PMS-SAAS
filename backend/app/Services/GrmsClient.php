<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Thin HTTP wrapper around the Guest Room Management System (in-room AC/lighting/
 * door-lock controller). Confirmed endpoints (api_token sent as a body field, not an
 * auth header, on both):
 *   - POST {base_url}/reservations/setReservationApi — on reservation creation.
 *     One room per call: first_name, last_name, mobile (exactly 10 digits),
 *     room_number, room_or_suite (1=room, 2=suite), start_date, expire_date.
 *   - POST {base_url}/reservations/checkoutApi — on guest check-out. One room per
 *     call: room_number, room_or_suite. (GRMS's own docs said singular "reservation/
 *     checkoutApi" — that 404s; the plural path below is the one that actually works
 *     against their sandbox.)
 *   - POST {base_url}/reservations/setRoomAvailableApi — marks a room ready for
 *     check-in again after housekeeping cleans it. room_number, jobNumber (the
 *     housekeeping job id — GRMS requires it but we don't track one yet, see
 *     SyncRoomAvailableToGrmsJob).
 */
class GrmsClient
{
    public function isEnabled(): bool
    {
        return (bool) config('grms.enabled') && filled(config('grms.base_url')) && filled(config('grms.api_token'));
    }

    /**
     * GRMS's own room numbers carry no building-letter prefix (e.g. our "A-201" is
     * their "201") — strip everything but digits before sending.
     */
    public static function normalizeRoomNumber(string $roomNumber): string
    {
        return preg_replace('/\D/', '', $roomNumber) ?? '';
    }

    /**
     * Client.mobile is stored WITHOUT the leading 0 (paired with
     * international_code — e.g. "+966" + "512345678"), but GRMS expects the
     * full local domestic number (10 digits, leading 0 included). Restore it
     * before sending, rather than passing the bare 9-digit subscriber number
     * through and having GRMS reject it as "at least 10 characters".
     */
    public static function normalizeMobileNumber(string $mobile): string
    {
        $digits = preg_replace('/\D/', '', $mobile) ?? '';

        if ($digits === '' || str_starts_with($digits, '0')) {
            return $digits;
        }

        return '0'.$digits;
    }

    /**
     * @param  array<string, mixed>  $payload  one room's worth of fields — see
     *                                         SyncReservationToGrmsJob::buildRoomPayload()
     *
     * @throws RuntimeException on failure, so the caller (the queued job) can retry.
     */
    public function setReservation(array $payload): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        $this->post('/reservations/setReservationApi', $payload, 'setReservationApi');
    }

    /**
     * @param  array{room_number: string, room_or_suite: int}  $payload
     *
     * @throws RuntimeException on failure, so the caller (the queued job) can retry.
     */
    public function checkout(array $payload): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        $this->post('/reservations/checkoutApi', $payload, 'checkoutApi');
    }

    /**
     * @param  array{room_number: string, jobNumber: string}  $payload
     *
     * @throws RuntimeException on failure, so the caller (the queued job) can retry.
     */
    public function setRoomAvailable(array $payload): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        $this->post('/reservations/setRoomAvailableApi', $payload, 'setRoomAvailableApi');
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function post(string $path, array $payload, string $label): void
    {
        $response = Http::baseUrl(config('grms.base_url'))
            ->timeout((int) config('grms.timeout'))
            ->post($path, [
                ...$payload,
                'api_token' => config('grms.api_token'),
            ]);

        // GRMS always answers HTTP 200 — even on failure — and the real outcome shape
        // varies by endpoint: setReservationApi nests it as {"response":{"original":
        // {"result":..., "error":...}}}, checkoutApi returns it flat as {"result":...,
        // "error":...}. A plain $response->failed() (HTTP-status-only) would silently
        // swallow both.
        $body = $response->json();
        $result = $body['response']['original']['result'] ?? $body['result'] ?? null;

        if ($response->failed() || $result !== 'success') {
            $error = $body['response']['original']['error'] ?? $body['error'] ?? $body['message'] ?? $response->body();
            // GRMS returns validation errors as an array/object on some failures
            // (e.g. per-field messages), not always a string.
            if (!is_string($error)) {
                $error = json_encode($error);
            }
            Log::warning("GRMS {$label} call", ['payload' => $payload, 'result' => 'failed', 'error' => $error]);
            throw new RuntimeException("GRMS {$label} failed: {$error}");
        }

        Log::info("GRMS {$label} call", ['payload' => $payload, 'result' => 'success']);
    }
}
