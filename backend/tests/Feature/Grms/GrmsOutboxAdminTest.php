<?php

namespace Tests\Feature\Grms;

use App\Models\GrmsOutboxEvent;
use App\Models\Hotel;
use App\Models\PlatformAdmin;
use App\Services\Grms\GrmsOutboxService;
use Tests\TestCase;

class GrmsOutboxAdminTest extends TestCase
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

    public function test_index_lists_only_review_and_rejected_events_for_the_hotel(): void
    {
        $operator = $this->platformAdmin();
        $hotel = $this->hotel();
        $service = app(GrmsOutboxService::class);

        $review = $service->enqueue((int) $hotel->id, 'reservation.created', 'reservation', '1', 1, []);
        $review->update(['status' => GrmsOutboxEvent::STATUS_REVIEW]);

        $rejected = $service->enqueue((int) $hotel->id, 'reservation.created', 'reservation', '2', 1, []);
        $rejected->update(['status' => GrmsOutboxEvent::STATUS_REJECTED]);

        $accepted = $service->enqueue((int) $hotel->id, 'reservation.created', 'reservation', '3', 1, []);
        $accepted->update(['status' => GrmsOutboxEvent::STATUS_ACCEPTED]);

        $response = $this->actingAs($operator, 'platform_admin')
            ->getJson("/api/platform-admin/hotels/{$hotel->id}/grms-outbox-events");

        $this->assertApiSuccess($response);
        $ids = collect($response->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($review->id));
        $this->assertTrue($ids->contains($rejected->id));
        $this->assertFalse($ids->contains($accepted->id));
    }

    public function test_retry_resets_a_stuck_event_back_to_pending(): void
    {
        $operator = $this->platformAdmin();
        $hotel = $this->hotel();
        $event = app(GrmsOutboxService::class)->enqueue((int) $hotel->id, 'reservation.created', 'reservation', '1', 1, []);
        $event->update([
            'status' => GrmsOutboxEvent::STATUS_REVIEW,
            'attempts' => 6,
            'last_error' => 'HTTP 500',
        ]);

        $response = $this->actingAs($operator, 'platform_admin')
            ->postJson("/api/platform-admin/grms-outbox-events/{$event->id}/retry");

        $this->assertApiSuccess($response);
        $fresh = $event->fresh();
        $this->assertSame(GrmsOutboxEvent::STATUS_PENDING, $fresh->status);
        $this->assertSame(0, $fresh->attempts);
        $this->assertNull($fresh->last_error);
    }

    public function test_updating_protocol_switches_the_hotel(): void
    {
        $operator = $this->platformAdmin();
        $hotel = $this->hotel();

        $response = $this->actingAs($operator, 'platform_admin')
            ->patchJson("/api/platform-admin/hotels/{$hotel->id}/grms-protocol", ['pms_protocol' => 'v1']);

        $this->assertApiSuccess($response);
        $this->assertSame('v1', $hotel->fresh()->pms_protocol);
    }

    public function test_updating_protocol_rejects_an_invalid_value(): void
    {
        $operator = $this->platformAdmin();
        $hotel = $this->hotel();

        $response = $this->actingAs($operator, 'platform_admin')
            ->patchJson("/api/platform-admin/hotels/{$hotel->id}/grms-protocol", ['pms_protocol' => 'nonsense']);

        $response->assertStatus(422);
    }
}
