<?php

namespace Tests\Feature\Grms;

use App\Models\GrmsHotelCredential;
use App\Models\GrmsOutboxEvent;
use App\Services\Grms\GrmsCredentialService;
use App\Services\Grms\GrmsOutboxService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DispatchGrmsOutboxCommandTest extends TestCase
{
    use \Illuminate\Foundation\Testing\DatabaseTransactions;

    protected function connectionsToTransact(): array
    {
        return ['mysql', 'mysql2'];
    }

    protected function setUp(): void
    {
        parent::setUp();
        config(['grms.enabled' => true, 'grms.base_url' => 'https://grms.test/api']);
    }

    private function hotelId(): int
    {
        return (int) DB::table('hotels')->orderBy('id')->value('id');
    }

    private function outboundCredential(int $hotelId): GrmsHotelCredential
    {
        return app(GrmsCredentialService::class)->generate($hotelId, GrmsHotelCredential::DIRECTION_OUTBOUND)['credential'];
    }

    public function test_an_accepted_event_is_marked_accepted(): void
    {
        $hotelId = $this->hotelId();
        $this->outboundCredential($hotelId);

        $event = app(GrmsOutboxService::class)->enqueue(
            $hotelId, 'reservation.created', 'reservation', '123', 1, ['reservation' => ['id' => '123']],
        );

        Http::fake([
            'grms.test/api/pms/v1/events' => Http::response([
                'results' => [['id' => $event->event_uuid, 'result' => 'accepted']],
            ], 200),
        ]);

        $this->artisan('grms:dispatch-outbox')->assertExitCode(0);

        $this->assertSame(GrmsOutboxEvent::STATUS_ACCEPTED, $event->fresh()->status);
    }

    public function test_an_http_failure_schedules_the_first_retry(): void
    {
        $hotelId = $this->hotelId();
        $this->outboundCredential($hotelId);

        $event = app(GrmsOutboxService::class)->enqueue(
            $hotelId, 'reservation.created', 'reservation', '456', 1, ['reservation' => ['id' => '456']],
        );

        Http::fake(['grms.test/api/pms/v1/events' => Http::response('server error', 500)]);

        $this->artisan('grms:dispatch-outbox')->assertExitCode(0);

        $fresh = $event->fresh();
        $this->assertSame(GrmsOutboxEvent::STATUS_PENDING, $fresh->status);
        $this->assertSame(1, $fresh->attempts);
        $this->assertTrue($fresh->next_attempt_at->greaterThan(now()->addSeconds(5)));
    }

    /**
     * Contract section 4: "a 4xx other than 408/429 is not repeated
     * (rejected)" — a hard auth/validation failure goes straight to review
     * instead of waiting through the whole 6-hour retry ladder first.
     */
    public function test_a_hard_4xx_skips_straight_to_review_without_retrying(): void
    {
        $hotelId = $this->hotelId();
        $this->outboundCredential($hotelId);

        $event = app(GrmsOutboxService::class)->enqueue(
            $hotelId, 'reservation.created', 'reservation', '401', 1, ['reservation' => ['id' => '401']],
        );

        Http::fake(['grms.test/api/pms/v1/events' => Http::response(['status' => 'rejected', 'reason' => 'invalid_signature'], 401)]);

        $this->artisan('grms:dispatch-outbox')->assertExitCode(0);

        $fresh = $event->fresh();
        $this->assertSame(GrmsOutboxEvent::STATUS_REVIEW, $fresh->status);
        $this->assertSame(1, $fresh->attempts);
    }

    /** 429 (rate limited) is explicitly excluded from the hard-4xx rule above — it still retries. */
    public function test_a_429_still_follows_the_normal_retry_schedule(): void
    {
        $hotelId = $this->hotelId();
        $this->outboundCredential($hotelId);

        $event = app(GrmsOutboxService::class)->enqueue(
            $hotelId, 'reservation.created', 'reservation', '429', 1, ['reservation' => ['id' => '429']],
        );

        Http::fake(['grms.test/api/pms/v1/events' => Http::response('too many requests', 429)]);

        $this->artisan('grms:dispatch-outbox')->assertExitCode(0);

        $fresh = $event->fresh();
        $this->assertSame(GrmsOutboxEvent::STATUS_PENDING, $fresh->status);
        $this->assertSame(1, $fresh->attempts);
    }

    public function test_an_event_moves_to_review_after_exhausting_all_retries(): void
    {
        $hotelId = $this->hotelId();
        $this->outboundCredential($hotelId);

        $event = app(GrmsOutboxService::class)->enqueue(
            $hotelId, 'reservation.created', 'reservation', '789', 1, ['reservation' => ['id' => '789']],
        );
        $event->update(['attempts' => count(GrmsOutboxEvent::RETRY_DELAYS_SECONDS)]);

        Http::fake(['grms.test/api/pms/v1/events' => Http::response('server error', 500)]);

        $this->artisan('grms:dispatch-outbox')->assertExitCode(0);

        $this->assertSame(GrmsOutboxEvent::STATUS_REVIEW, $event->fresh()->status);
    }

    public function test_a_hotel_without_an_active_outbound_credential_is_skipped_without_error(): void
    {
        $hotelId = $this->hotelId();

        app(GrmsOutboxService::class)->enqueue(
            $hotelId, 'reservation.created', 'reservation', '999', 1, ['reservation' => ['id' => '999']],
        );

        Http::fake();

        $this->artisan('grms:dispatch-outbox')->assertExitCode(0);

        Http::assertNothingSent();
    }
}
