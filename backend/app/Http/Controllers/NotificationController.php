<?php

namespace App\Http\Controllers;

use App\Models\AppNotification;
use Illuminate\Http\Request;

/**
 * Staff-facing notification feed shown from the header bell — server-side
 * filtered per user (hotel + permission), so a receptionist and an
 * accountant looking at the same bell genuinely see different lists.
 */
class NotificationController extends Controller
{
    private function currentHotelId(Request $request): int
    {
        return (int) $request->attributes->get('currentHotelId');
    }

    public function index(Request $request)
    {
        $user = $request->user();
        $hotelId = $this->currentHotelId($request);

        $notifications = AppNotification::query()
            ->visibleTo($user, $hotelId)
            ->orderByDesc('created_at')
            ->limit(30)
            ->get();

        $seenAt = $user->notifications_seen_at;
        $unreadCount = $seenAt
            ? $notifications->filter(fn (AppNotification $n) => $n->created_at->greaterThan($seenAt))->count()
            : $notifications->count();

        return \SuccessData('Notifications retrieved', [
            'notifications' => $notifications,
            'unread_count' => $unreadCount,
        ]);
    }

    /** Opening the bell "reads" everything currently in the list — there is no per-item read state. */
    public function markSeen(Request $request)
    {
        $request->user()->update(['notifications_seen_at' => now()]);

        return \Success('Marked as seen');
    }
}
