<?php

namespace App\Http\Controllers;

use App\Models\GrmsOutboxEvent;
use App\Models\Hotel;
use App\Services\Grms\GrmsOutboxService;

/**
 * Platform-admin-only visibility into GRMS v1 outbox events that need a
 * human look — either exhausted their retry schedule (STATUS_REVIEW, see
 * DispatchGrmsOutboxCommand) or came back explicitly rejected by GRMS
 * (STATUS_REJECTED, not retried — see DispatchGrmsOutboxCommand::applyResult()).
 * Reachable only through the "platform_admin" guard, same as
 * GrmsCredentialController.
 */
class GrmsOutboxAdminController extends Controller
{
    public function __construct(private GrmsOutboxService $outboxService) {}

    public function index(Hotel $hotel)
    {
        try {
            $perPage = \returnPerPage();
            $events = GrmsOutboxEvent::where('hotel_id', $hotel->id)
                ->whereIn('status', [GrmsOutboxEvent::STATUS_REVIEW, GrmsOutboxEvent::STATUS_REJECTED])
                ->orderByDesc('updated_at')
                ->paginate($perPage);

            return \Pagination($events);
        } catch (\Exception $e) {
            return \Failed($e->getMessage());
        }
    }

    /** Re-queues a stuck event for another attempt, resetting its retry schedule from the top. */
    public function retry(GrmsOutboxEvent $event)
    {
        try {
            $this->outboxService->requeue($event);

            return \SuccessData('Event re-queued', $event->fresh());
        } catch (\Exception $e) {
            return \Failed($e->getMessage());
        }
    }
}
