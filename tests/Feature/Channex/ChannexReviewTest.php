<?php

namespace Tests\Feature\Channex;

use App\Models\ChannexProperty;
use App\Models\Hotel;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Thin-proxy coverage for the guest-reviews page — Channex is the sole
 * source of truth for review content and replies, so this only verifies
 * the request/response shape and error handling, via Http::fake() rather
 * than the real sandbox (confirmed live to return 403 Forbidden on this
 * account's current Channex plan — Reviews is evidently a gated feature not
 * included by default, so this cannot be verified against the real API yet).
 */
class ChannexReviewTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['channex.enabled' => true, 'channex.api_key' => 'test-key']);
    }

    /** @return array{0: int, 1: string, 2: User} [hotelId, channexPropertyId, user] */
    private function seedHotelWithReviewAccess(): array
    {
        $marker = 'Channex Review Test '.uniqid();
        $hotel = Hotel::create(['name_ar' => $marker, 'name_en' => $marker, 'is_active' => true]);
        $propertyId = 'test-review-property-'.$hotel->id;
        ChannexProperty::create(['hotel_id' => $hotel->id, 'channex_property_id' => $propertyId]);

        $registrar = app(PermissionRegistrar::class);
        $previousTeam = $registrar->getPermissionsTeamId();
        $registrar->setPermissionsTeamId($hotel->id);

        Permission::findOrCreate('manage channex reviews', 'api');
        $role = Role::create(['name' => 'review-test-role-'.uniqid(), 'guard_name' => 'api']);
        $role->syncPermissions(['manage channex reviews']);

        $user = User::create([
            'name' => 'Review Staff '.uniqid(),
            'job_number' => 'REVIEW-'.uniqid(),
            'email' => 'review.'.uniqid().'@example.com',
            'mobile' => '05'.substr(uniqid(), -8),
            'active' => 1,
            'password' => bcrypt('test-password'),
        ]);
        $user->hotels()->syncWithoutDetaching([$hotel->id]);
        $user->assignRole($role);

        $registrar->setPermissionsTeamId($previousTeam);

        return [(int) $hotel->id, $propertyId, $user];
    }

    public function test_index_lists_reviews_filtered_by_the_hotels_channex_property(): void
    {
        [$hotelId, $propertyId, $user] = $this->seedHotelWithReviewAccess();

        Http::fake([
            '*/reviews*' => Http::response(['data' => [
                ['id' => 'rev-1', 'content' => 'Lovely stay', 'overall_score' => 9, 'ota' => 'BookingCom'],
            ], 'meta' => ['page' => 1, 'limit' => 20]], 200),
        ]);

        $response = $this->actingAs($user, 'api')
            ->withHeader('X-Hotel-Id', (string) $hotelId)
            ->getJson('/api/users/channex/reviews');

        $response->assertOk();
        $response->assertJsonPath('data.reviews.0.id', 'rev-1');

        Http::assertSent(fn ($req) => str_contains($req->url(), '/reviews')
            && ($req->data()['property_id'] ?? null) === $propertyId);
    }

    public function test_index_fails_gracefully_when_the_hotel_has_no_channex_property(): void
    {
        $marker = 'Unconnected Hotel '.uniqid();
        $hotel = Hotel::create(['name_ar' => $marker, 'name_en' => $marker, 'is_active' => true]);

        $registrar = app(PermissionRegistrar::class);
        $registrar->setPermissionsTeamId($hotel->id);
        Permission::findOrCreate('manage channex reviews', 'api');
        $role = Role::create(['name' => 'unconnected-role-'.uniqid(), 'guard_name' => 'api']);
        $role->syncPermissions(['manage channex reviews']);
        $user = User::create([
            'name' => 'Unconnected Staff',
            'job_number' => 'UNCON-'.uniqid(),
            'email' => 'unconnected.'.uniqid().'@example.com',
            'mobile' => '05'.substr(uniqid(), -8),
            'active' => 1,
            'password' => bcrypt('test-password'),
        ]);
        $user->hotels()->syncWithoutDetaching([$hotel->id]);
        $user->assignRole($role);

        $response = $this->actingAs($user, 'api')
            ->withHeader('X-Hotel-Id', (string) $hotel->id)
            ->getJson('/api/users/channex/reviews');

        $response->assertJsonPath('success', false);
    }

    public function test_reply_requires_a_non_empty_reply_text(): void
    {
        [$hotelId, , $user] = $this->seedHotelWithReviewAccess();

        $response = $this->actingAs($user, 'api')
            ->withHeader('X-Hotel-Id', (string) $hotelId)
            ->postJson('/api/users/channex/reviews/rev-1/reply', ['reply' => '']);

        $response->assertStatus(422);
    }

    public function test_reply_posts_to_channex_and_returns_success(): void
    {
        [$hotelId, , $user] = $this->seedHotelWithReviewAccess();

        Http::fake([
            '*/reviews/rev-1/reply' => Http::response(['data' => ['id' => 'rev-1', 'reply' => 'Thank you!']], 200),
        ]);

        $response = $this->actingAs($user, 'api')
            ->withHeader('X-Hotel-Id', (string) $hotelId)
            ->postJson('/api/users/channex/reviews/rev-1/reply', ['reply' => 'Thank you!']);

        $response->assertOk();
        $response->assertJsonPath('success', true);

        Http::assertSent(fn ($req) => str_contains($req->url(), '/reviews/rev-1/reply')
            && $req['reply']['reply'] === 'Thank you!');
    }

    public function test_scores_returns_the_detailed_property_score(): void
    {
        [$hotelId, $propertyId, $user] = $this->seedHotelWithReviewAccess();

        Http::fake([
            "*/scores/{$propertyId}/detailed" => Http::response(['data' => ['overall_score' => 8.5]], 200),
        ]);

        $response = $this->actingAs($user, 'api')
            ->withHeader('X-Hotel-Id', (string) $hotelId)
            ->getJson('/api/users/channex/reviews-scores');

        $response->assertOk();
        $response->assertJsonPath('data.overall_score', 8.5);
    }

    /**
     * Confirmed live against the real Channex sandbox: a property with no
     * reviews yet returns 404, not an empty array — the UI must show an
     * empty state, not an error banner.
     */
    public function test_scores_treats_a_404_as_an_empty_state_not_an_error(): void
    {
        [$hotelId, $propertyId, $user] = $this->seedHotelWithReviewAccess();

        Http::fake([
            "*/scores/{$propertyId}/detailed" => Http::response(['errors' => ['title' => 'Resource Not Found']], 404),
        ]);

        $response = $this->actingAs($user, 'api')
            ->withHeader('X-Hotel-Id', (string) $hotelId)
            ->getJson('/api/users/channex/reviews-scores');

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data', null);
    }
}
