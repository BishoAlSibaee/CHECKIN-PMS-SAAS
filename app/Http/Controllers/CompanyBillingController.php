<?php

namespace App\Http\Controllers;

use App\Services\CompanyBillingReservationService;
use Illuminate\Http\Request;

/**
 * A separate endpoint (not an overload of ReservationController::makeReservation())
 * so MakeReservationRequest's own validation stays untouched — same
 * philosophy already used for group bookings and Channex ingestion.
 */
class CompanyBillingController extends Controller
{
    public function __construct(private CompanyBillingReservationService $service) {}

    public function store(Request $request)
    {
        $request->validate([
            'company_id' => 'required|numeric|exists:companies,id',
            'client_id' => 'required|numeric',
            'rooms' => 'required|array|min:1',
            'rooms.*.room_id' => 'nullable|numeric|exists:rooms,id',
            'rooms.*.suite_id' => 'nullable|numeric|exists:suites,id',
            'start_date' => 'required|date|after_or_equal:today',
            'expire_date' => 'required|date|after:start_date',
            'stay_reason_id' => 'required|numeric|exists:stay_reasons,id',
            'reservation_source_id' => 'required|numeric|exists:reservation_sources,id',
            'rent_type' => 'required|in:0,1',
            'price_calculation_mode' => 'required|in:0,1,2',
            'discount' => 'nullable|numeric|min:0',
            'extras' => 'nullable|numeric|min:0',
            'penalties' => 'nullable|numeric|min:0',
        ]);

        try {
            $currentHotelId = (int) $request->attributes->get('currentHotelId');
            $userId = (int) auth()->id();

            $draft = $request->only([
                'client_id', 'rooms', 'start_date', 'expire_date', 'stay_reason_id',
                'reservation_source_id', 'rent_type', 'price_calculation_mode',
                'discount', 'extras', 'penalties',
            ]);
            $draft['reservation_status'] = \App\Models\Reservation::STATUS_CONFIRMED;

            $reservation = $this->service->createCompanyBilledReservation(
                $draft,
                (int) $request->company_id,
                $currentHotelId,
                $userId
            );

            return \SuccessData('Reservation created and billed to company', $reservation);
        } catch (\RuntimeException $e) {
            return \Failed($e->getMessage(), 422);
        } catch (\Exception $e) {
            return \Failed($e->getMessage());
        }
    }
}
