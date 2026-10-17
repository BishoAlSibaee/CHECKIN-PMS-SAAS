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
 * Thin-proxy coverage for the guest-messages inbox page — Channex is the
 * sole source of truth for thread/message content and the reply itself, so
 * this only verifies request/response shape and error handling via
 * Http::fake() rather than the real sandbox.
 */
class ChannexMessageTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['channex.enabled' => true, 'channex.api_key' => 'test-key']);
    }

    /** @return array{0: int, 1: string, 2: User} [hotelId, channexPropertyId, user] */
    private function seedHotelWithMessageAccess(): array
    {
        $marker = 'Channex Message Test '.uniqid();
        $hotel = Hotel::create(['name_ar' => $marker, 'name_en' => $marker, 'is_active' => true]);
        $propertyId = 'test-message-property-'.$hotel->id;
        ChannexProperty::create(['hotel_id' => $hotel->id, 'channex_property_id' => $propertyId]);

        $registrar = app(PermissionRegistrar::class);
        $previousTeam = $registrar->getPermissionsTeamId();
        $registrar->setPermissionsTeamId($hotel->id);

        Permission::findOrCreate('manage channex messages', 'api');
        $role = Role::create(['name' => 'message-test-role-'.uniqid(), 'guard_name' => 'api']);
        $role->syncPermissions(['manage channex messages']);

        $user = User::create([
            'name' => 'Message Staff '.uniqid(),
            'job_number' => 'MSG-'.uniqid(),
            'email' => 'message.'.uniqid().'@example.com',
            'mobile' => '05'.substr(uniqid(), -8),
            'active' => 1,
            'password' => bcrypt('test-password'),
        ]);
        $user->hotels()->syncWithoutDetaching([$hotel->id]);
        $user->assignRole($role);

        $registrar->setPermissionsTeamId($previousTeam);

        return [(int) $hotel->id, $propertyId, $user];
    }

    public function test_index_lists_message_threads_filtered_by_the_hotels_channex_property(): void
    {
        [$hotelId, $propertyId, $user] = $this->seedHotelWithMessageAccess();

        Http::fake([
            '*/message_threads*' => Http::response(['data' => [
                ['id' => 'thread-1', 'title' => 'John Doe', 'provider' => 'Airbnb', 'message_count' => 2],
            ], 'meta' => ['page' => 1, 'limit' => 20]], 200),
        ]);

        $response = $this->actingAs($user, 'api')
            ->withHeader('X-Hotel-Id', (string) $hotelId)
            ->getJson('/api/users/channex/messages');

        $response->assertOk();
        $response->assertJsonPath('data.threads.0.id', 'thread-1');

        Http::assertSent(fn ($req) => str_contains($req->url(), '/message_threads')
            && ($req->data()['property_id'] ?? null) === $propertyId);
    }

    public function test_index_fails_gracefully_when_the_hotel_has_no_channex_property(): void
    {
        $marker = 'Unconnected Hotel '.uniqid();
        $hotel = Hotel::create(['name_ar' => $marker, 'name_en' => $marker, 'is_active' => true]);

        $registrar = app(PermissionRegistrar::class);
        $registrar->setPermissionsTeamId($hotel->id);
        Permission::findOrCreate('manage channex messages', 'api');
        $role = Role::create(['name' => 'unconnected-role-'.uniqid(), 'guard_name' => 'api']);
        $role->syncPermissions(['manage channex messages']);
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
            ->getJson('/api/users/channex/messages');

        $response->assertJsonPath('success', false);
    }

    public function test_messages_returns_the_threads_full_conversation(): void
    {
        [$hotelId, , $user] = $this->seedHotelWithMessageAccess();

        Http::fake([
            '*/message_threads/thread-1/messages' => Http::response(['data' => [
                ['message' => 'Hi, what time is check-in?', 'sender' => 'guest', 'inserted_at' => '2026-09-29T10:00:00Z'],
                ['message' => 'Check-in is at 3 PM.', 'sender' => 'property', 'inserted_at' => '2026-09-29T10:05:00Z'],
            ]], 200),
        ]);

        $response = $this->actingAs($user, 'api')
            ->withHeader('X-Hotel-Id', (string) $hotelId)
            ->getJson('/api/users/channex/messages/thread-1');

        $response->assertOk();
        $response->assertJsonCount(2, 'data');
    }

    public function test_reply_requires_a_non_empty_message(): void
    {
        [$hotelId, , $user] = $this->seedHotelWithMessageAccess();

        $response = $this->actingAs($user, 'api')
            ->withHeader('X-Hotel-Id', (string) $hotelId)
            ->postJson('/api/users/channex/messages/thread-1/reply', ['message' => '']);

        $response->assertStatus(422);
    }

    public function test_reply_posts_to_the_thread_and_returns_success(): void
    {
        [$hotelId, , $user] = $this->seedHotelWithMessageAccess();

        Http::fake([
            '*/message_threads/thread-1/messages' => Http::response(['data' => ['message' => 'Check-in is at 3 PM.', 'sender' => 'property']], 200),
        ]);

        $response = $this->actingAs($user, 'api')
            ->withHeader('X-Hotel-Id', (string) $hotelId)
            ->postJson('/api/users/channex/messages/thread-1/reply', ['message' => 'Check-in is at 3 PM.']);

        $response->assertOk();
        $response->assertJsonPath('success', true);

        Http::assertSent(fn ($req) => str_contains($req->url(), '/message_threads/thread-1/messages')
            && $req['message']['message'] === 'Check-in is at 3 PM.');
    }

    public function test_no_reply_needed_marks_the_thread_accordingly(): void
    {
        [$hotelId, , $user] = $this->seedHotelWithMessageAccess();

        Http::fake([
            '*/message_threads/thread-1/no_reply_needed' => Http::response(['data' => ['id' => 'thread-1']], 200),
        ]);

        $response = $this->actingAs($user, 'api')
            ->withHeader('X-Hotel-Id', (string) $hotelId)
            ->postJson('/api/users/channex/messages/thread-1/no-reply-needed');

        $response->assertOk();
        $response->assertJsonPath('success', true);
    }

    public function test_close_closes_the_thread(): void
    {
        [$hotelId, , $user] = $this->seedHotelWithMessageAccess();

        Http::fake([
            '*/message_threads/thread-1/close' => Http::response(['data' => ['id' => 'thread-1', 'is_closed' => true]], 200),
        ]);

        $response = $this->actingAs($user, 'api')
            ->withHeader('X-Hotel-Id', (string) $hotelId)
            ->postJson('/api/users/channex/messages/thread-1/close');

        $response->assertOk();
        $response->assertJsonPath('data.is_closed', true);
    }
}
