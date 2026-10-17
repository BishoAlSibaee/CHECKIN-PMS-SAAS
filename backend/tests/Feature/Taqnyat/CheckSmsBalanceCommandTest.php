<?php

namespace Tests\Feature\Taqnyat;

use App\Models\AppNotification;
use App\Models\Hotel;
use App\Services\Taqnyat\TaqnyatCredentialStore;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CheckSmsBalanceCommandTest extends TestCase
{
    private function makeHotelWithCredentials(): Hotel
    {
        $marker = 'Taqnyat Test '.uniqid();
        $hotel = Hotel::create(['name_ar' => $marker, 'name_en' => $marker, 'is_active' => true]);
        app(TaqnyatCredentialStore::class)->save(['token' => 'test-token'], (int) $hotel->id);

        return $hotel;
    }

    public function test_low_balance_creates_exactly_one_notification(): void
    {
        $hotel = $this->makeHotelWithCredentials();

        Http::fake([
            'api.taqnyat.sa/account/balance' => Http::response(['balance' => 5, 'currency' => 'SAR'], 200),
        ]);

        $this->artisan('taqnyat:check-balance')->assertExitCode(0);

        $this->assertSame(1, AppNotification::where('hotel_id', $hotel->id)->where('type', 'sms_balance_low')->count());
    }

    public function test_healthy_balance_creates_no_notification(): void
    {
        $hotel = $this->makeHotelWithCredentials();

        Http::fake([
            'api.taqnyat.sa/account/balance' => Http::response(['balance' => 500, 'currency' => 'SAR'], 200),
        ]);

        $this->artisan('taqnyat:check-balance')->assertExitCode(0);

        $this->assertSame(0, AppNotification::where('hotel_id', $hotel->id)->where('type', 'sms_balance_low')->count());
    }

    public function test_running_twice_in_the_same_day_does_not_duplicate_the_alert(): void
    {
        $hotel = $this->makeHotelWithCredentials();

        Http::fake([
            'api.taqnyat.sa/account/balance' => Http::response(['balance' => 5, 'currency' => 'SAR'], 200),
        ]);

        $this->artisan('taqnyat:check-balance')->assertExitCode(0);
        $this->artisan('taqnyat:check-balance')->assertExitCode(0);

        $this->assertSame(1, AppNotification::where('hotel_id', $hotel->id)->where('type', 'sms_balance_low')->count());
    }
}
