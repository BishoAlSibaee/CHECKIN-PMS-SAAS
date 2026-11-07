<?php

namespace Tests\Feature\Grms;

use App\Models\GrmsHotelCredential;
use App\Models\Hotel;
use App\Models\PlatformAdmin;
use App\Services\Grms\GrmsCredentialService;
use Tests\TestCase;

class GrmsCredentialTest extends TestCase
{
    use \Illuminate\Foundation\Testing\DatabaseTransactions;

    protected function connectionsToTransact(): array
    {
        return ['mysql', 'mysql2'];
    }

    private function platformAdmin(): PlatformAdmin
    {
        $suffix = (string) random_int(10000000, 99999999);

        return PlatformAdmin::create([
            'name' => 'Operator '.$suffix,
            'email' => 'operator-'.$suffix.'@test.local',
            'password' => bcrypt('x'),
            'is_active' => true,
        ]);
    }

    private function hotel(): Hotel
    {
        $hotelId = \DB::table('hotels')->orderBy('id')->value('id');

        return Hotel::findOrFail($hotelId);
    }

    public function test_generating_a_credential_returns_the_secret_once(): void
    {
        $operator = $this->platformAdmin();
        $hotel = $this->hotel();

        $response = $this->actingAs($operator, 'platform_admin')
            ->postJson("/api/platform-admin/hotels/{$hotel->id}/grms-credentials", [
                'direction' => GrmsHotelCredential::DIRECTION_OUTBOUND,
            ]);

        $this->assertApiSuccess($response);
        $this->assertNotEmpty($response->json('data.secret'));
        $this->assertNotEmpty($response->json('data.key_id'));

        $this->assertDatabaseHas('grms_hotel_credentials', [
            'hotel_id' => $hotel->id,
            'direction' => GrmsHotelCredential::DIRECTION_OUTBOUND,
            'is_active' => 1,
        ]);
    }

    public function test_the_secret_is_never_returned_from_the_index_listing(): void
    {
        $operator = $this->platformAdmin();
        $hotel = $this->hotel();
        app(GrmsCredentialService::class)->generate((int) $hotel->id, GrmsHotelCredential::DIRECTION_OUTBOUND);

        $response = $this->actingAs($operator, 'platform_admin')
            ->getJson("/api/platform-admin/hotels/{$hotel->id}/grms-credentials");

        $this->assertApiSuccess($response);
        $row = $response->json('data.0');
        $this->assertArrayNotHasKey('secret', $row);
    }

    public function test_generating_a_new_credential_deactivates_the_old_one(): void
    {
        $hotel = $this->hotel();
        $service = app(GrmsCredentialService::class);

        $first = $service->generate((int) $hotel->id, GrmsHotelCredential::DIRECTION_OUTBOUND);
        $second = $service->generate((int) $hotel->id, GrmsHotelCredential::DIRECTION_OUTBOUND);

        $this->assertNotSame($first['credential']->key_id, $second['credential']->key_id);
        $this->assertFalse($first['credential']->fresh()->is_active);
        $this->assertTrue($second['credential']->fresh()->is_active);
    }

    public function test_the_secret_round_trips_through_encryption_correctly(): void
    {
        $hotel = $this->hotel();
        $result = app(GrmsCredentialService::class)->generate((int) $hotel->id, GrmsHotelCredential::DIRECTION_INBOUND);

        $reloaded = GrmsHotelCredential::findOrFail($result['credential']->id);

        $this->assertSame($result['secret'], $reloaded->secret);

        // The raw DB column must NOT contain the plaintext secret.
        $rawValue = \DB::table('grms_hotel_credentials')->where('id', $reloaded->id)->value('secret');
        $this->assertStringNotContainsString($result['secret'], $rawValue);
    }

    public function test_active_secret_for_resolves_by_key_id(): void
    {
        $hotel = $this->hotel();
        $service = app(GrmsCredentialService::class);
        $result = $service->generate((int) $hotel->id, GrmsHotelCredential::DIRECTION_OUTBOUND);

        $resolved = $service->activeSecretFor($result['credential']->key_id);

        $this->assertSame($result['secret'], $resolved);
        $this->assertNull($service->activeSecretFor('does-not-exist'));
    }
}
