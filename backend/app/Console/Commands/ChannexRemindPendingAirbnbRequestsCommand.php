<?php

namespace App\Console\Commands;

use App\Models\ChannexReservationRequest;
use App\Models\HotelAirbnbRequestSetting;
use App\Services\Channex\ChannexReservationRequestService;
use Illuminate\Console\Command;

/**
 * Staff won't see a pending Airbnb "Request to Book" unless someone tells
 * them — this re-notifies (live, in-app) on whatever cadence each hotel's
 * admin configured (reminder_interval_minutes), for as long as the request
 * stays unanswered, right up until the 24h fallback resolves it.
 */
class ChannexRemindPendingAirbnbRequestsCommand extends Command
{
    protected $signature = 'channex:remind-pending-airbnb-requests';

    protected $description = 'Re-notify staff about unanswered Airbnb reservation requests, per each hotel\'s reminder interval';

    public function handle(ChannexReservationRequestService $service): int
    {
        $pending = ChannexReservationRequest::where('status', ChannexReservationRequest::STATUS_PENDING)->get();
        $remindedCount = 0;

        foreach ($pending as $request) {
            $settings = HotelAirbnbRequestSetting::forHotel((int) $request->hotel_id);
            if ($settings->mode !== HotelAirbnbRequestSetting::MODE_MANUAL_REVIEW) {
                // Shouldn't normally still be pending under auto_accept/auto_decline,
                // but if the mode changed after this request arrived, don't nag staff
                // about something the policy no longer asks them to decide.
                continue;
            }

            $lastNotifiedAt = $request->last_reminder_sent_at ?? $request->received_at;
            $dueAt = $lastNotifiedAt->copy()->addMinutes(max(1, (int) $settings->reminder_interval_minutes));

            if (now()->greaterThanOrEqualTo($dueAt)) {
                $service->notifyStaff($request);
                $remindedCount++;
            }
        }

        $this->info("Reminded staff about {$remindedCount} pending Airbnb request(s).");

        return self::SUCCESS;
    }
}
