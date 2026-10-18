<?php

namespace Tests\Feature\Taqnyat;

use App\Models\Client;
use App\Models\Client_Classifications;
use App\Models\Guest_classification;
use App\Models\PromoCampaign;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PromoCampaignTest extends TestCase
{
    use DatabaseTransactions;

    protected function connectionsToTransact(): array
    {
        return ['mysql', 'mysql2'];
    }

    private function makeClient(string $marker): Client
    {
        return Client::create([
            'first_name' => 'Guest',
            'last_name' => $marker,
            'email' => 'guest.'.uniqid().'@example.com',
            'international_code' => '966',
            'mobile' => '05'.random_int(10000000, 99999999),
            'IdType' => 'PASSPORT',
            'IdNumber' => 'TEST-'.uniqid(),
            'gender' => 'MALE',
            'guest_type' => 'VISITOR',
        ]);
    }

    public function test_classification_audience_preview_returns_only_clients_in_that_classification(): void
    {
        $user = $this->userWithApiPermissions(['manage external subscriptions']);
        $marker = 'Promo Test '.uniqid();

        $classification = Guest_classification::create([
            'name_ar' => $marker, 'name_en' => $marker, 'description' => 'test', 'active' => 1,
        ]);

        $inClient = $this->makeClient('In-'.$marker);
        $outClient = $this->makeClient('Out-'.$marker);

        Client_Classifications::create(['classifications_id' => $classification->id, 'client_id' => $inClient->id]);

        $response = $this->actingAs($user, 'api')->getJson('/api/users/promo-campaigns/audience-preview?'.http_build_query([
            'audience_type' => 'classification',
            'guest_classification_id' => $classification->id,
        ]));

        $response->assertOk();
        $ids = collect($response->json('data.clients'))->pluck('id')->all();

        $this->assertContains($inClient->id, $ids);
        $this->assertNotContains($outClient->id, $ids);
        $this->assertSame(1, $response->json('data.count'));
        $expectedPhone = '966'.ltrim($inClient->mobile, '0');
        $this->assertSame($expectedPhone, $response->json('data.clients.0.phone'));
    }

    public function test_manual_audience_preview_returns_exactly_the_requested_clients(): void
    {
        $user = $this->userWithApiPermissions(['manage external subscriptions']);
        $client = $this->makeClient('Manual-'.uniqid());

        $response = $this->actingAs($user, 'api')->getJson('/api/users/promo-campaigns/audience-preview?'.http_build_query([
            'audience_type' => 'manual',
            'client_ids' => [$client->id],
        ]));

        $response->assertOk();
        $this->assertSame(1, $response->json('data.count'));
        $this->assertSame($client->id, $response->json('data.clients.0.id'));
    }

    public function test_store_persists_a_draft_with_a_recipient_snapshot(): void
    {
        $user = $this->userWithApiPermissions(['manage external subscriptions']);
        $client = $this->makeClient('Draft-'.uniqid());

        $response = $this->actingAs($user, 'api')->postJson('/api/users/promo-campaigns', [
            'message_body' => 'عرض خاص لضيوفنا الكرام',
            'audience_type' => 'manual',
            'client_ids' => [$client->id],
        ]);

        $response->assertOk();
        $campaignId = $response->json('data.id');

        $this->assertDatabaseHas('promo_campaigns', [
            'id' => $campaignId,
            'status' => PromoCampaign::STATUS_DRAFT,
            'recipient_count' => 1,
        ]);
        $this->assertDatabaseHas('promo_campaign_recipients', [
            'promo_campaign_id' => $campaignId,
            'client_id' => $client->id,
            'phone_snapshot' => '966'.ltrim($client->mobile, '0'),
        ]);
    }

    public function test_user_without_permission_is_forbidden(): void
    {
        $user = $this->userWithApiPermissions();

        $response = $this->actingAs($user, 'api')->getJson('/api/users/promo-campaigns');
        $response->assertStatus(403);

        $balanceResponse = $this->actingAs($user, 'api')->getJson('/api/users/external-subscriptions/taqnyat');
        $balanceResponse->assertStatus(403);
    }

    /**
     * Safety-critical: drafting a campaign — the one write path this
     * feature exposes — must never call Taqnyat's send endpoint.
     */
    public function test_storing_a_campaign_never_calls_the_send_endpoint(): void
    {
        Http::fake();
        $user = $this->userWithApiPermissions(['manage external subscriptions']);
        $client = $this->makeClient('NoSend-'.uniqid());

        $this->actingAs($user, 'api')->postJson('/api/users/promo-campaigns', [
            'message_body' => 'test',
            'audience_type' => 'manual',
            'client_ids' => [$client->id],
        ])->assertOk();

        Http::assertNothingSent();
    }
}
