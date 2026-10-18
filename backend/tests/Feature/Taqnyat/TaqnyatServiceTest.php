<?php

namespace Tests\Feature\Taqnyat;

use App\Services\Taqnyat\TaqnyatCredentialStore;
use App\Services\Taqnyat\TaqnyatService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TaqnyatServiceTest extends TestCase
{
    use \Illuminate\Foundation\Testing\DatabaseTransactions;

    protected function connectionsToTransact(): array
    {
        return ['mysql', 'mysql2'];
    }

    public function test_returns_not_configured_when_no_credentials_saved(): void
    {
        $service = app(TaqnyatService::class);
        $result = $service->getBalance(999999);

        $this->assertFalse($result['configured']);
    }

    public function test_fetches_and_parses_balance_when_credentials_exist(): void
    {
        $hotelId = 888888;
        app(TaqnyatCredentialStore::class)->save(['token' => 'test-token'], $hotelId);

        Http::fake([
            'api.taqnyat.sa/account/balance' => Http::response([
                'accountStatus' => 'active',
                'accountExpiryDate' => '2027-01-01',
                'balance' => 123.45,
                'currency' => 'SAR',
            ], 200),
        ]);

        $service = app(TaqnyatService::class);
        $result = $service->getBalance($hotelId);

        $this->assertTrue($result['configured']);
        $this->assertEqualsWithDelta(123.45, $result['balance'], 0.01);
        $this->assertSame('SAR', $result['currency']);
        $this->assertSame('active', $result['account_status']);
        $this->assertSame('2027-01-01', $result['account_expiry_date']);

        Http::assertSent(fn ($request) => $request->url() === 'https://api.taqnyat.sa/account/balance'
            && $request->hasHeader('Authorization', 'Bearer test-token'));

        $this->cleanupCredentials($hotelId);
    }

    public function test_handles_a_failed_api_response_gracefully(): void
    {
        $hotelId = 888889;
        app(TaqnyatCredentialStore::class)->save(['token' => 'bad-token'], $hotelId);

        Http::fake([
            'api.taqnyat.sa/account/balance' => Http::response(['message' => 'Unauthorized'], 401),
        ]);

        $service = app(TaqnyatService::class);
        $result = $service->getBalance($hotelId);

        $this->assertTrue($result['configured']);
        $this->assertArrayHasKey('error', $result);

        $this->cleanupCredentials($hotelId);
    }

    /**
     * This is the safety-critical assertion from the plan: no code path in
     * this service ever calls Taqnyat's send endpoint.
     */
    public function test_never_calls_the_send_message_endpoint(): void
    {
        $hotelId = 888890;
        app(TaqnyatCredentialStore::class)->save(['token' => 'test-token'], $hotelId);

        Http::fake([
            'api.taqnyat.sa/account/balance' => Http::response(['balance' => 10], 200),
        ]);

        app(TaqnyatService::class)->getBalance($hotelId);

        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'v1/messages'));
        $this->assertFalse(method_exists(TaqnyatService::class, 'send'), 'TaqnyatService must not have a send() method in this phase.');

        $this->cleanupCredentials($hotelId);
    }

    private function cleanupCredentials(int $hotelId): void
    {
        $path = app(TaqnyatCredentialStore::class)->path($hotelId);
        if (is_file($path)) {
            unlink($path);
            @rmdir(dirname($path));
        }
    }
}
