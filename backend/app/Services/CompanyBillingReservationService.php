<?php

namespace App\Services;

use App\Http\Controllers\ReservationController;
use App\Http\Requests\Reservation\MakeReservationRequest;
use App\Models\Company;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\Suite;
use App\Services\Accounting\AccountingPostingService;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Creates a reservation billed to a company's corporate account, reusing
 * ReservationController::makeReservation() exactly as GroupBookingCreationService
 * and ChannexBookingIngestionService do — rather than duplicating its
 * pricing/tax/accrual logic.
 */
class CompanyBillingReservationService
{
    public function __construct(
        private ReservationController $reservationController,
        private AccountingPostingService $accountingPostingService,
        private GuestClassificationDiscountService $guestClassificationDiscountService,
        private PricingEngine $pricingEngine,
    ) {}

    /** @param  array<string, mixed>  $draft  same field shape as MakeReservationRequest */
    public function createCompanyBilledReservation(array $draft, int $companyId, int $hotelId, int $userId): Reservation
    {
        $company = Company::findOrFail($companyId);
        if (! $company->isActive()) {
            throw new RuntimeException('This company account is not active.');
        }

        $basePrice = $this->priceRooms($draft);

        if ($company->credit_limit !== null) {
            $currentBalance = $this->accountingPostingService->statementForCompany($company->id)['balance'];
            if ($currentBalance + $basePrice > $company->credit_limit + 0.005) {
                throw new RuntimeException(
                    "This booking would exceed {$company->name}'s credit limit ".
                    "(outstanding: {$currentBalance}, limit: {$company->credit_limit})."
                );
            }
        }

        return DB::transaction(function () use ($draft, $company, $hotelId, $userId, $basePrice) {
            $discountAmount = $company->discount
                ? $this->guestClassificationDiscountService->computeAmount($company->discount, $basePrice)
                : 0.0;

            $payload = $draft;
            $payload['discount'] = round((float) ($draft['discount'] ?? 0) + $discountAmount, 2);
            $payload['reservation_type'] = $draft['reservation_type'] ?? 0;

            $request = MakeReservationRequest::create('/api/users/makeReservation', 'POST', $payload);
            $request->setContainer(app());
            $request->attributes->set('currentHotelId', $hotelId);

            $response = $this->reservationController->makeReservation($request);
            $payloadOut = json_decode($response->getContent(), true);
            if (! ($payloadOut['success'] ?? false)) {
                throw new RuntimeException('Failed to create the reservation: '.($payloadOut['message'] ?? 'unknown error'));
            }

            $reservationId = (int) ($payloadOut['data']['reservation']['id'] ?? 0);
            $reservation = Reservation::findOrFail($reservationId);
            $reservation->update(['company_id' => $company->id]);

            $this->accountingPostingService->postCompanyBillingRecognition($reservation->fresh(), $userId);

            return $reservation->fresh(['client', 'reservationRooms.room', 'company']);
        });
    }

    /** @param  array<string, mixed>  $draft */
    private function priceRooms(array $draft): float
    {
        $rentType = (int) ($draft['rent_type'] ?? 0);
        $priceMode = (int) ($draft['price_calculation_mode'] ?? 0);

        $totalBasePrice = 0.0;
        foreach ((array) ($draft['rooms'] ?? []) as $roomData) {
            $rooms = ! empty($roomData['suite_id'])
                ? Suite::findOrFail($roomData['suite_id'])->rooms()->where('active', 1)->get()
                : [Room::findOrFail($roomData['room_id'])];

            foreach ($rooms as $room) {
                $lines = $this->pricingEngine->buildDailyBreakdown(
                    $room,
                    $draft['start_date'],
                    $draft['expire_date'],
                    $rentType,
                    $priceMode
                );
                $totalBasePrice += $this->pricingEngine->sumBaseAmount($lines);
            }
        }

        return $totalBasePrice;
    }
}
