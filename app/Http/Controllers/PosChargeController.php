<?php

namespace App\Http\Controllers;

use App\Models\PosCharge;
use App\Models\Reservation;
use App\Models\Room;
use App\Services\Accounting\AccountingPostingService;
use App\Services\ReservationRoomStatusService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Standalone quick-entry cashier screen for POS sales (restaurant, cafe,
 * laundry, minibar): look up a room, optionally attach a walk-in guest with
 * no room at all, add a charge either paid immediately (cash — never touches
 * the reservation) or charged to the room folio (adds to the reservation
 * balance and blocks checkout until settled).
 */
class PosChargeController extends Controller
{
    private const TAX_RATE = 0.15;

    public function __construct(
        private AccountingPostingService $postingService,
        private ReservationRoomStatusService $roomStatusService,
    ) {}

    public function lookupRoom(Request $request)
    {
        $request->validate([
            'room_number' => 'required|string|max:50',
        ]);

        try {
            $currentHotelId = $request->attributes->get('currentHotelId');

            $room = Room::where('number', $request->room_number)
                ->whereHas('building', fn ($q) => $q->when($currentHotelId, fn ($q2) => $q2->where('hotel_id', $currentHotelId)))
                ->first();

            if (! $room) {
                return \Failed('No room found with this number.', 404);
            }

            $reservationId = $this->roomStatusService->findActiveInHouseReservationId((int) $room->id);
            $reservation = $reservationId ? Reservation::with('client')->find($reservationId) : null;

            return \SuccessData('Room lookup result', [
                'room_id' => $room->id,
                'room_number' => $room->number,
                'reservation_id' => $reservation?->id,
                'guest_name' => $reservation?->client
                    ? trim($reservation->client->first_name.' '.$reservation->client->last_name)
                    : null,
            ]);
        } catch (\Exception $e) {
            return \Failed($e->getMessage());
        }
    }

    public function index(Request $request)
    {
        try {
            $currentHotelId = $request->attributes->get('currentHotelId');
            $perPage = \returnPerPage();

            $charges = PosCharge::query()
                ->when($currentHotelId, fn ($q) => $q->where('hotel_id', $currentHotelId))
                ->when($request->filled('reservation_id'), fn ($q) => $q->where('reservation_id', $request->reservation_id))
                ->when($request->filled('pos_category_id'), fn ($q) => $q->where('pos_category_id', $request->pos_category_id))
                ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
                ->when($request->filled('start_date'), fn ($q) => $q->whereDate('charge_date', '>=', $request->start_date))
                ->when($request->filled('end_date'), fn ($q) => $q->whereDate('charge_date', '<=', $request->end_date))
                ->with(['category', 'reservation', 'creator'])
                ->orderByDesc('id')
                ->paginate($perPage);

            return \Pagination($charges);
        } catch (\Exception $e) {
            return \Failed($e->getMessage());
        }
    }

    public function store(Request $request)
    {
        $request->validate([
            'pos_category_id' => 'required|numeric|exists:pos_categories,id',
            'description' => 'nullable|string|max:255',
            'invoice_reference' => 'nullable|string|max:100',
            'quantity' => 'required|numeric|min:0.01',
            'unit_price' => 'required|numeric|min:0',
            'payment_status' => 'required|in:0,1',
            'reservation_id' => 'nullable|numeric|exists:reservations,id',
            'walkin_reference' => 'nullable|string|max:255',
        ]);

        $isChargedToRoom = (int) $request->payment_status === PosCharge::PAYMENT_STATUS_CHARGED_TO_ROOM;

        if ($isChargedToRoom && ! $request->filled('reservation_id')) {
            return \Failed('A reservation is required to charge a POS sale to the room.', 422);
        }

        try {
            $currentHotelId = (int) $request->attributes->get('currentHotelId');

            $reservation = null;
            if ($request->filled('reservation_id')) {
                $reservation = Reservation::findOrFail($request->reservation_id);
                if (Reservation::isCancelled((int) $reservation->reservation_status)) {
                    return \Failed('Cannot add a POS charge to a cancelled reservation.', 422);
                }
            }

            $quantity = (float) $request->quantity;
            $unitPrice = (float) $request->unit_price;
            $amount = round($quantity * $unitPrice, 2);
            $taxAmount = round($amount * self::TAX_RATE, 2, PHP_ROUND_HALF_UP);

            $charge = DB::transaction(function () use ($request, $currentHotelId, $reservation, $quantity, $unitPrice, $amount, $taxAmount, $isChargedToRoom) {
                $charge = PosCharge::create([
                    'hotel_id' => $currentHotelId,
                    'reservation_id' => $reservation?->id,
                    'pos_category_id' => $request->pos_category_id,
                    'description' => $request->description,
                    'invoice_reference' => $request->invoice_reference,
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'amount' => $amount,
                    'tax_amount' => $taxAmount,
                    'charge_date' => now()->toDateString(),
                    'payment_status' => $request->payment_status,
                    'status' => PosCharge::STATUS_ACTIVE,
                    'walkin_reference' => $isChargedToRoom ? null : $request->walkin_reference,
                    'created_by' => auth()->id(),
                ]);

                $this->postingService->postPosCharge($charge, (int) auth()->id());

                return $charge;
            });

            return \SuccessData('POS charge recorded', $charge->load('category'));
        } catch (\Exception $e) {
            return \Failed($e->getMessage());
        }
    }

    public function void(Request $request, int $id)
    {
        try {
            $charge = PosCharge::findOrFail($id);
            if (! $charge->isActive()) {
                return \Failed('This charge has already been voided.', 422);
            }

            DB::transaction(function () use ($charge) {
                $this->postingService->reverseVoidedPosCharge($charge);

                $charge->update([
                    'status' => PosCharge::STATUS_VOIDED,
                    'voided_by' => auth()->id(),
                    'voided_at' => now(),
                ]);
            });

            return \SuccessData('POS charge voided', $charge);
        } catch (\Exception $e) {
            return \Failed($e->getMessage());
        }
    }
}
