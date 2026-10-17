<?php

namespace App\Http\Controllers\Channex;

use App\Http\Controllers\Controller;
use App\Models\HotelAirbnbRequestSetting;
use Illuminate\Http\Request;

/**
 * Admin-configurable Airbnb "Request to Book" policy per hotel: accept
 * directly, manual review (with a reminder cadence and a 24h-timeout
 * fallback), or decline directly.
 */
class AirbnbRequestSettingController extends Controller
{
    private function currentHotelId(Request $request): int
    {
        return (int) $request->attributes->get('currentHotelId');
    }

    public function show(Request $request)
    {
        $settings = HotelAirbnbRequestSetting::forHotel($this->currentHotelId($request));

        return \SuccessData('Airbnb request settings retrieved', $settings);
    }

    public function update(Request $request)
    {
        $request->validate([
            'mode' => 'required|string|in:'.implode(',', [
                HotelAirbnbRequestSetting::MODE_AUTO_ACCEPT,
                HotelAirbnbRequestSetting::MODE_MANUAL_REVIEW,
                HotelAirbnbRequestSetting::MODE_AUTO_DECLINE,
            ]),
            'reminder_interval_minutes' => 'required_if:mode,'.HotelAirbnbRequestSetting::MODE_MANUAL_REVIEW.'|integer|min:1',
            'fallback_action' => 'required_if:mode,'.HotelAirbnbRequestSetting::MODE_MANUAL_REVIEW.'|string|in:'.implode(',', [
                HotelAirbnbRequestSetting::FALLBACK_ACCEPT,
                HotelAirbnbRequestSetting::FALLBACK_DECLINE,
            ]),
            'decline_reason' => 'nullable|string|in:'.implode(',', HotelAirbnbRequestSetting::DECLINE_REASONS),
        ]);

        $settings = HotelAirbnbRequestSetting::forHotel($this->currentHotelId($request));
        $settings->update([
            'mode' => $request->input('mode'),
            'reminder_interval_minutes' => $request->input('reminder_interval_minutes', $settings->reminder_interval_minutes),
            'fallback_action' => $request->input('fallback_action', $settings->fallback_action),
            'decline_reason' => $request->input('decline_reason', $settings->decline_reason),
            'updated_by' => auth()->id(),
        ]);

        return \SuccessData('Airbnb request settings updated', $settings->fresh());
    }
}
