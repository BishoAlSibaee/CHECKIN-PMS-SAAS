<?php

namespace Tests\Feature\Grms;

use App\Jobs\Grms\DispatchGrmsOutboxForHotelJob;
use App\Models\GrmsOutboxEvent;
use App\Services\Grms\GrmsOutboxService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * enqueue()'s near-immediate dispatch — see DispatchGrmsOutboxCommandTest
 * for the retry/backoff logic this complements (that file fakes the queue
 * so this side effect doesn't interfere with testing the command path).
 */
class GrmsOutboxServiceTest extends TestCase
{
    use \Illuminate\Foundation\Testing\DatabaseTransactions;

    protected function connectionsToTransact(): array
    {
        return ['mysql', 'mysql2'];
    }

    private function hotelId(): int
    {
        return (int) DB::table('hotels')->orderBy('id')->value('id');
    }

    public function test_enqueue_dispatches_an_immediate_job_for_the_hotel(): void
    {
        Queue::fake();
        $hotelId = $this->hotelId();

        app(GrmsOutboxService::class)->enqueue(
            $hotelId, 'reservation.created', 'reservation', '999', 1, ['reservation' => ['id' => '999']],
        );

        Queue::assertPushed(DispatchGrmsOutboxForHotelJob::class, function (DispatchGrmsOutboxForHotelJob $job) use ($hotelId) {
            return $job->hotelId === $hotelId;
        });
    }

    public function test_enqueue_still_creates_the_outbox_row_with_queue_faked(): void
    {
        Queue::fake();
        $hotelId = $this->hotelId();

        $event = app(GrmsOutboxService::class)->enqueue(
            $hotelId, 'reservation.created', 'reservation', '998', 1, ['reservation' => ['id' => '998']],
        );

        $this->assertDatabaseHas('grms_outbox_events', [
            'id' => $event->id,
            'hotel_id' => $hotelId,
            'type' => 'reservation.created',
            'status' => GrmsOutboxEvent::STATUS_PENDING,
        ]);
    }

    public function test_a_replaced_pending_event_still_dispatches_a_fresh_immediate_job(): void
    {
        Queue::fake();
        $hotelId = $this->hotelId();
        $service = app(GrmsOutboxService::class);

        $service->enqueue($hotelId, 'reservation.updated', 'reservation', '997', 1, ['a' => 1]);
        $service->enqueue($hotelId, 'reservation.updated', 'reservation', '997', 2, ['a' => 2]);

        Queue::assertPushed(DispatchGrmsOutboxForHotelJob::class, 2);
        $this->assertSame(1, GrmsOutboxEvent::where('entity_id', '997')->count());
    }
}
