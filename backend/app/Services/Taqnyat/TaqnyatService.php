<?php

namespace App\Services\Taqnyat;

use Illuminate\Support\Facades\Http;

/**
 * Taqnyat SMS — balance read-only in this phase. There is deliberately NO
 * method here that calls POST https://api.taqnyat.sa/v1/messages (sending).
 * See the plan doc: message sending is explicitly out of scope until the
 * hotel's content-approval process is in place. Do not add a send method
 * without the user's explicit sign-off — PromoCampaignTest asserts no call
 * to that endpoint ever happens.
 */
class TaqnyatService
{
    private const BALANCE_URL = 'https://api.taqnyat.sa/account/balance';

    public function __construct(private TaqnyatCredentialStore $credentialStore) {}

    /**
     * @return array{configured: bool, balance?: float, currency?: string, account_status?: string, account_expiry_date?: string|null, error?: string}
     */
    public function getBalance(int $hotelId): array
    {
        $credentials = $this->credentialStore->load($hotelId);
        if (! $credentials || empty($credentials['token'])) {
            return ['configured' => false];
        }

        try {
            $response = Http::withToken($credentials['token'])
                ->timeout(10)
                ->get(self::BALANCE_URL);
        } catch (\Throwable $e) {
            return ['configured' => true, 'error' => $e->getMessage()];
        }

        if (! $response->successful()) {
            return ['configured' => true, 'error' => 'Taqnyat balance request failed with status '.$response->status()];
        }

        $data = $response->json();

        return [
            'configured' => true,
            'balance' => (float) ($data['balance'] ?? 0),
            'currency' => (string) ($data['currency'] ?? 'SAR'),
            'account_status' => (string) ($data['accountStatus'] ?? ''),
            'account_expiry_date' => $data['accountExpiryDate'] ?? null,
        ];
    }
}
