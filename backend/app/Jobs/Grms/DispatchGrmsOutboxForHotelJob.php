<?php

namespace App\Jobs\Grms;

use App\Services\Grms\GrmsOutboxDispatcher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * The near-immediate dispatch attempt — queued right after
 * GrmsOutboxService::enqueue() so an event doesn't sit waiting for the
 * once-a-minute DispatchGrmsOutboxCommand safety net under normal
 * conditions. Just calls GrmsOutboxDispatcher, same as that command does,
 * so this and the per-minute sweep can never disagree on what "sent"
 * means — only one tries here, deliberately: a failure's retry schedule
 * lives in the outbox event's own next_attempt_at/attempts (see
 * GrmsOutboxDispatcher::markFailedBatch()), not in the queue's own retry
 * mechanism — letting both retry independently would double-send.
 */
class DispatchGrmsOutboxForHotelJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(public int $hotelId) {}

    public function handle(GrmsOutboxDispatcher $dispatcher): void
    {
        $dispatcher->dispatchForHotel($this->hotelId);
    }
}
