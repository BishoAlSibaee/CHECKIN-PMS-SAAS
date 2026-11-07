<?php

namespace App\Http\Controllers\Grms;

use App\Http\Controllers\Controller;
use App\Models\GrmsHotelCredential;
use App\Models\PosCategory;
use App\Models\PosCharge;
use App\Models\Reservation;
use App\Services\Accounting\AccountingPostingService;
use App\Services\Grms\GrmsSignatureService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * GRMS v1 contract synchronous charges (POST/DELETE /api/grms/v1/charges) —
 * posts a guest's restaurant/cafe/laundry/minibar order straight to their
 * folio. One GRMS "charge" can carry several line items; each becomes its
 * own PosCharge row, grouped by the shared grms_charge_id for idempotency
 * and void. Reuses PosChargeController's own posting/reversal logic
 * (AccountingPostingService::postPosCharge/reverseVoidedPosCharge) rather
 * than duplicating it.
 */
class GrmsChargeController extends Controller
{
    /**
     * Contract's department/category enum (section 3b: restaurant, cafe,
     * laundry, room_service, minibar, other) -> our pos_categories.name_en
     * (global seed, 2026_09_30_300001 + 2026_11_07_040000 for "Other").
     */
    private const CATEGORY_NAME_MAP = [
        'restaurant' => 'Restaurant',
        'cafe' => 'Cafe',
        'laundry' => 'Laundry',
        'minibar' => 'Minibar',
        // Owner decision: no dedicated room-service account (it's normally
        // free) — both unmapped departments fold into the one "Other"
        // catch-all (parking, gym, or any paid service outside the four
        // named ones; a rare priced room-service item also lands here).
        'other' => 'Other',
        'room_service' => 'Other',
    ];

    public function __construct(
        private GrmsSignatureService $signatureService,
        private AccountingPostingService $postingService,
    ) {}

    public function store(Request $request)
    {
        $credential = $this->authenticate($request);
        if ($credential instanceof JsonResponse) {
            return $credential;
        }

        $chargeId = (string) $request->input('charge_id', '');
        if ($chargeId === '') {
            return response()->json(['status' => 'rejected', 'reason' => 'invalid_amount'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $existing = PosCharge::where('grms_charge_id', $chargeId)->active()->get();
        if ($existing->isNotEmpty()) {
            return response()->json(['status' => 'posted', 'folio_entry_id' => $this->folioEntryId($chargeId)], Response::HTTP_OK);
        }

        $reservation = Reservation::with('reservationRooms.room')->find($request->input('reservation_id'));
        if (!$reservation) {
            return $this->rejected('reservation_not_found');
        }

        if ($this->isCheckedOut($reservation)) {
            return $this->rejected('guest_checked_out');
        }

        if ($this->isFolioClosed($reservation)) {
            return $this->rejected('folio_closed');
        }

        $roomCode = (string) $request->input('room.code', $request->input('room')['code'] ?? '');
        $roomOnReservation = $reservation->reservationRooms->first(
            fn ($rr) => !$rr->isCancelled($rr->status) && (string) ($rr->room?->number) === $roomCode,
        );
        if ($roomCode !== '' && !$roomOnReservation) {
            return $this->rejected('room_mismatch');
        }

        $items = $request->input('items', []);
        if (empty($items)) {
            return $this->rejected('invalid_amount');
        }

        // Contract section 6: each item carries its own `category`, falling
        // back to the charge's overall `department` when absent; `code` is
        // optional and opaque (never used to classify — see class docblock).
        $department = (string) $request->input('department', '');
        $categoryIds = [];
        foreach ($items as $index => $item) {
            $categoryName = (string) ($item['category'] ?? $department);
            $category = PosCategory::where('name_en', self::CATEGORY_NAME_MAP[$categoryName] ?? '__none__')
                ->where(fn ($q) => $q->whereNull('hotel_id')->orWhere('hotel_id', $credential->hotel_id))
                ->first();
            if (!$category) {
                return $this->rejected('unknown_item_code');
            }
            $categoryIds[$index] = $category->id;
        }

        $pricesIncludeTax = (bool) $request->input('prices_include_tax', false);
        $taxRate = ((float) $request->input('tax_rate_percent', 15)) / 100;

        try {
            DB::transaction(function () use ($items, $categoryIds, $chargeId, $credential, $reservation, $pricesIncludeTax, $taxRate) {
                foreach ($items as $index => $item) {
                    $quantity = (float) ($item['quantity'] ?? 1);
                    $unitAmountMinor = (float) ($item['unit_price']['amount'] ?? 0);
                    $grossUnit = round($unitAmountMinor / 100, 2);
                    $grossTotal = round($grossUnit * $quantity, 2);

                    if ($pricesIncludeTax) {
                        $netTotal = round($grossTotal / (1 + $taxRate), 2);
                        $taxAmount = round($grossTotal - $netTotal, 2);
                    } else {
                        $netTotal = $grossTotal;
                        $taxAmount = round($netTotal * $taxRate, 2, PHP_ROUND_HALF_UP);
                    }

                    $charge = PosCharge::create([
                        'hotel_id' => $credential->hotel_id,
                        'grms_charge_id' => $chargeId,
                        'reservation_id' => $reservation->id,
                        'pos_category_id' => $categoryIds[$index],
                        'description' => (string) ($item['description'] ?? ''),
                        'quantity' => $quantity,
                        'unit_price' => $quantity > 0 ? round($netTotal / $quantity, 2) : 0,
                        'amount' => $netTotal,
                        'tax_amount' => $taxAmount,
                        'charge_date' => now()->toDateString(),
                        'payment_status' => PosCharge::PAYMENT_STATUS_CHARGED_TO_ROOM,
                        'status' => PosCharge::STATUS_ACTIVE,
                        'created_by' => null,
                    ]);

                    $this->postingService->postPosCharge($charge, null);
                }
            });
        } catch (\Throwable) {
            return $this->rejected('invalid_amount');
        }

        return response()->json([
            'status' => 'posted',
            'folio_entry_id' => $this->folioEntryId($chargeId),
        ], Response::HTTP_OK);
    }

    /** Idempotent — voiding an already-voided charge_id is a no-op success. */
    public function destroy(Request $request, string $chargeId)
    {
        $credential = $this->authenticate($request);
        if ($credential instanceof JsonResponse) {
            return $credential;
        }

        $charges = PosCharge::where('grms_charge_id', $chargeId)->active()->get();

        foreach ($charges as $charge) {
            DB::transaction(function () use ($charge) {
                $this->postingService->reverseVoidedPosCharge($charge);
                $charge->update(['status' => PosCharge::STATUS_VOIDED]);
            });
        }

        return response()->json(['status' => 'voided'], Response::HTTP_OK);
    }

    private function authenticate(Request $request): GrmsHotelCredential|JsonResponse
    {
        $keyId = $request->header('X-Key-Id');
        $credential = $keyId ? GrmsHotelCredential::where('key_id', $keyId)
            ->where('direction', GrmsHotelCredential::DIRECTION_INBOUND)
            ->where('is_active', true)
            ->first() : null;

        if (!$credential) {
            return response()->json(['message' => 'Unknown or inactive key_id'], Response::HTTP_UNAUTHORIZED);
        }

        $verification = $this->signatureService->verify($request, $credential->secret);
        if (!$verification['ok']) {
            return response()->json(['message' => $verification['reason']], Response::HTTP_UNAUTHORIZED);
        }

        if (!\App\Services\Grms\GrmsProtocolGuard::allows((int) $credential->hotel_id, 'v1')) {
            return response()->json(['message' => 'other_protocol'], Response::HTTP_CONFLICT);
        }

        return $credential;
    }

    private function isCheckedOut(Reservation $reservation): bool
    {
        return (int) $reservation->logedin === Reservation::LOGEDIN_NOT_IN_HOUSE && filled($reservation->login_time);
    }

    private function isFolioClosed(Reservation $reservation): bool
    {
        return (string) $reservation->expire_date < now()->toDateString() && !$this->isCheckedOut($reservation);
    }

    private function rejected(string $reason): JsonResponse
    {
        return response()->json(['status' => 'rejected', 'reason' => $reason], Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    private function folioEntryId(string $chargeId): string
    {
        return 'pos-'.$chargeId;
    }
}
