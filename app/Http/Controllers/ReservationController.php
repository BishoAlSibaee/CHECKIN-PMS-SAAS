<?php

namespace App\Http\Controllers;

use App\Models\PeakDay;
use App\Models\PeakMonth;
use App\Models\Reservation;
use App\Models\ReservationRoom;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\RoomtypePricingplan;
use App\Models\Suite;
use App\Models\ReservationPay;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use App\Http\Requests\Reservation\MakeReservationRequest;
use App\Http\Requests\GetByClientIdRequest;
use App\Http\Requests\Reservation\BookingRoomAvailabilityRequest;
use App\Http\Requests\Reservation\CheckReservationRequest;
use App\Http\Requests\Reservation\GetRoomPriceRequest;
use App\Http\Requests\Reservation\GetReservationByDateRequest;
use App\Http\Requests\Reservation\RefundRequest;
use App\Http\Requests\Reservation\UpdateReservationRequest;
use App\Http\Requests\Reservation\AddReservationPaymentRequest;
use App\Models\ReservationDailyCharge;
use Illuminate\Http\Request;
use App\Models\RoomPrice;
use App\Models\RoomPriceMaxDay;
use App\Models\RoomPriceMaxMonth;
use App\Services\PricingEngine;
use App\Services\RevenueAccrualService;
use App\Services\ReservationRoomStatusService;
use App\Services\Accounting\AccountingPostingService;
use App\Services\ReservationFinancialService;
use App\Services\RefundPolicyService;
use App\Services\HotelLivePublisher;
use App\Exceptions\RefundNotAllowedException;
use App\Http\Requests\Reservation\CancelReservationRequest;
use App\Http\Requests\Reservation\ExtendReservationRequest;
use App\Http\Requests\Reservation\ShortenReservationRequest;
use App\Services\ReservationChangeLogService;
use App\Services\ReservationShortenService;
use Carbon\CarbonPeriod;

class ReservationController extends Controller
{
    public function __construct(
        private PricingEngine $pricingEngine,
        private RevenueAccrualService $revenueAccrualService,
        private ReservationRoomStatusService $roomStatusService,
        private AccountingPostingService $accountingPostingService,
        private ReservationFinancialService $reservationFinancialService,
        private RefundPolicyService $refundPolicyService,
        private ReservationShortenService $shortenService,
        private HotelLivePublisher $livePublisher,
        private ReservationChangeLogService $changeLogService
    ) {
    }

    private function notifyReservationLive(int $reservationId, string $action, bool $affectsInventory = false): void
    {
        try {
            $scopes = [
                HotelLivePublisher::SCOPE_RESERVATIONS,
                HotelLivePublisher::SCOPE_RESERVATION_DETAIL,
                HotelLivePublisher::SCOPE_COLLECTIONS,
            ];

            if ($affectsInventory) {
                $scopes = array_merge($scopes, [
                    HotelLivePublisher::SCOPE_OCCUPANCY_BOARD,
                    HotelLivePublisher::SCOPE_PROPERTY,
                    HotelLivePublisher::SCOPE_DASHBOARD,
                ]);
            }

            $this->livePublisher->publish(array_values(array_unique($scopes)), $action, [
                'type' => 'reservation',
                'id'   => $reservationId,
            ]);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * Roll back an open transaction and return a failed API response.
     */
    private function failedTransaction(string $message, int $status = 500)
    {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }

        return \Failed($message, $status);
    }

    public function index(Request $request)
    {
        try {
            $perPage = \returnPerPage();
            $query = Reservation::with(['client', 'reservationRooms.room.roomType', 'payments'])
                ->excludingCancelled();

            if ($request->filled('reservation_status')) {
                $query->where('reservation_status', (int) $request->reservation_status);
            }
            if ($request->filled('date_from')) {
                $query->where('expire_date', '>=', $request->date_from);
            }
            if ($request->filled('date_to')) {
                $query->where('start_date', '<=', $request->date_to);
            }
            if ($request->filled('client_id')) {
                $query->where('client_id', (int) $request->client_id);
            }
            if ($request->filled('search')) {
                $term = trim($request->search);
                $query->where(function ($q) use ($term) {
                    if (preg_match('/^\d+$/', $term)) {
                        $q->where('reservations.id', (int) $term);
                    }
                    $q->orWhereHas('client', function ($clientQuery) use ($term) {
                        $clientQuery->where('first_name', 'like', "%{$term}%")
                            ->orWhere('last_name', 'like', "%{$term}%")
                            ->orWhere('mobile', 'like', "%{$term}%")
                            ->orWhere('email', 'like', "%{$term}%");
                    });
                    $q->orWhereHas('reservationRooms.room', function ($roomQuery) use ($term) {
                        $roomQuery->where('number', 'like', "%{$term}%");
                    });
                });
            }
            if ($request->filled('room_number')) {
                $roomNumber = trim($request->room_number);
                $query->whereHas('reservationRooms.room', function ($roomQuery) use ($roomNumber) {
                    $roomQuery->where('number', $roomNumber);
                });
            }

            if ($request->boolean('has_balance_due')) {
                $query->withPositiveBalance();
            }

            if ($request->input('sort') === 'balance_due') {
                $paymentType = \App\Models\ReservationPay::TYPE_PAYMENT;
                $refundType = \App\Models\ReservationPay::TYPE_REFUND;
                $query->orderByRaw(
                    '(reservations.total - COALESCE((
                        SELECT SUM(CASE WHEN rp.type = ? THEN rp.pay WHEN rp.type = ? THEN -rp.pay ELSE 0 END)
                        FROM reservation_pay rp WHERE rp.reservation_id = reservations.id
                    ), 0)) DESC',
                    [$paymentType, $refundType]
                )->orderByDesc('id');
            } else {
                $query->orderByDesc('start_date')->orderByDesc('id');
            }

            $reservations = $query->paginate($perPage);

            return \Pagination($reservations);
        } catch (\Exception $e) {
            return \Failed($e->getMessage());
        }
    }

    public function show(int $id)
    {
        try {
            $reservation = Reservation::with([
                'client',
                'reservationRooms.room.roomType',
                'reservationRooms.room.building',
                'reservationRooms.room.floor',
                'payments',
                'user',
            ])->findOrFail($id);

            return \SuccessData('Reservation retrieved', $this->reservationDetailPayload($reservation));
        } catch (ModelNotFoundException) {
            return \Failed('Reservation not found', 404);
        } catch (\Exception $e) {
            return \Failed($e->getMessage());
        }
    }

    public function update(UpdateReservationRequest $request, int $id)
    {
        try {
            DB::beginTransaction();

            $reservation = Reservation::with(['reservationRooms.room', 'payments'])->findOrFail($id);

            $beforeSnapshot = $this->changeLogService->snapshot($reservation);

            $today = Carbon::today()->toDateString();
            $isCheckingOut = false;
            $pendingLogedin = null;
            $willCheckOut = $request->has('logedin')
                && (int) $request->logedin === Reservation::LOGEDIN_NOT_IN_HOUSE
                && (int) $reservation->logedin === Reservation::LOGEDIN_IN_HOUSE;

            if ($request->filled('start_date') || $request->filled('expire_date')) {
                if (Reservation::isCancelled((int) $reservation->reservation_status)) {
                    return $this->failedTransaction('Cannot modify dates of a cancelled reservation.');
                }

                if ($reservation->expire_date < $today && !$willCheckOut) {
                    return $this->failedTransaction('Cannot modify dates of a completed stay.');
                }

                if ((int) $reservation->logedin === 1 && $request->filled('start_date')) {
                    return $this->failedTransaction('Cannot change check-in date while the guest is checked in.');
                }

                $newStart = $request->filled('start_date')
                    ? $request->start_date
                    : $reservation->start_date;
                $newExpire = $request->filled('expire_date')
                    ? $request->expire_date
                    : $reservation->expire_date;

                if ($newExpire < $today) {
                    return $this->failedTransaction('Checkout date cannot be before today.');
                }

                if ($newExpire <= $newStart) {
                    return $this->failedTransaction('Checkout date must be after check-in date.');
                }

                $reservation->start_date = $newStart;
                $reservation->expire_date = $newExpire;
            }

            if ($request->has('reservation_status')) {
                $reservation->reservation_status = (int) $request->reservation_status;
            }
            if ($request->has('logedin')) {
                $expireDate = $reservation->expire_date;
                $newLogedin = (int) $request->logedin;

                if (Reservation::isCancelled((int) $reservation->reservation_status)) {
                    return $this->failedTransaction('Cannot change check-in status of a cancelled reservation.');
                }

                if ($expireDate < $today) {
                    if ($newLogedin === Reservation::LOGEDIN_IN_HOUSE) {
                        return $this->failedTransaction('Check-in is not allowed after the scheduled departure date.');
                    }
                    if ((int) $reservation->logedin !== Reservation::LOGEDIN_IN_HOUSE) {
                        return $this->failedTransaction('This stay has already ended.');
                    }
                }

                if ($newLogedin === 1 && $reservation->start_date > $today) {
                    return $this->failedTransaction('Check-in is not allowed before the arrival date.');
                }

                if ($newLogedin === 1) {
                    try {
                        $this->roomStatusService->assertRoomsReadyForCheckIn($reservation);
                    } catch (\RuntimeException $e) {
                        return $this->failedTransaction($e->getMessage());
                    }
                }

                $isCheckingOut = $newLogedin === 0 && (int) $reservation->logedin === 1;
                $pendingLogedin = $newLogedin;
            }

            if ($request->has('discount')) {
                $reservation->discount = (float) $request->discount;
            }
            if ($request->has('extras')) {
                $reservation->extras = (float) $request->extras;
            }
            if ($request->has('penalties')) {
                $reservation->penalties = (float) $request->penalties;
            }

            if ($this->reservationFinancialService->requestNeedsPricingRecalculation($request, $reservation)) {
                $this->recalculateReservationPricing($reservation);
            }

            if ($isCheckingOut) {
                $balance = $reservation->balanceDue();
                if ($balance > 0.005) {
                    return $this->failedTransaction(
                        'Outstanding balance must be paid before check-out. Remaining: ' . number_format($balance, 2, '.', ''),
                        422
                    );
                }
            }

            if ($pendingLogedin !== null) {
                $reservation->logedin = $pendingLogedin;
            }

            if ($request->filled('login_time')) {
                $reservation->login_time = $request->login_time;
            } elseif ($pendingLogedin === 1 && !$reservation->login_time) {
                $reservation->login_time = Carbon::today()->toDateString();
            }

            $reservation->save();

            if ($request->has('logedin')
                && (int) $request->logedin === 0
                && $isCheckingOut) {
                foreach ($reservation->reservationRooms as $resRoom) {
                    if ($resRoom->room_id) {
                        $this->roomStatusService->markNeedsPreparation($resRoom->room_id);
                    }
                }
            }

            $this->roomStatusService->syncForReservation($reservation->fresh(['reservationRooms']));

            $logAction = 'updated';
            if ($pendingLogedin === Reservation::LOGEDIN_IN_HOUSE) {
                $logAction = 'checked_in';
            } elseif ($isCheckingOut) {
                $logAction = 'checked_out';
            }
            $afterSnapshot = $this->changeLogService->snapshot(
                $reservation->fresh(['reservationRooms.room', 'payments'])
            );
            $this->changeLogService->recordIfChanged($id, $logAction, $beforeSnapshot, $afterSnapshot);

            DB::commit();

            $action = 'reservation_updated';
            if ($pendingLogedin === Reservation::LOGEDIN_IN_HOUSE) {
                $action = 'checked_in';
            } elseif ($isCheckingOut) {
                $action = 'checked_out';
            }
            $affectsInventory = $pendingLogedin !== null
                || $request->filled('start_date')
                || $request->filled('expire_date');
            $this->notifyReservationLive($id, $action, $affectsInventory);

            return $this->show($id);
        } catch (\Exception $e) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            return \Failed($e->getMessage());
        }
    }

    public function calendar(Request $request)
    {
        try {
            $from = $request->input('date_from', Carbon::today()->startOfMonth()->toDateString());
            $to = $request->input('date_to', Carbon::today()->endOfMonth()->toDateString());

            $query = Reservation::with(['client', 'reservationRooms.room', 'payments'])
                ->excludingCancelled()
                ->where('start_date', '<=', $to)
                ->where('expire_date', '>=', $from);

            if ($request->filled('client_id')) {
                $query->where('client_id', (int) $request->client_id);
            }

            $events = $query->orderBy('start_date')->get()->map(function (Reservation $r) {
                $room = $r->reservationRooms->first()?->room;
                $paid = (float) $r->payments
                    ->where('type', ReservationPay::TYPE_PAYMENT)
                    ->sum('pay');
                $refunded = (float) $r->payments
                    ->where('type', ReservationPay::TYPE_REFUND)
                    ->sum('pay');
                $paidNet = round($paid - $refunded, 2);
                $balanceDue = round(max(0, (float) $r->total - $paidNet), 2);

                return [
                    'id' => $r->id,
                    'title' => trim(($r->client->first_name ?? '') . ' ' . ($r->client->last_name ?? ''))
                        . ' · ' . ($room?->number ?? '—'),
                    'start' => $r->start_date,
                    'end' => $r->expire_date,
                    'status' => (int) $r->reservation_status,
                    'logedin' => (int) $r->logedin,
                    'calendar_state' => $this->resolveCalendarState($r, $paidNet, $balanceDue),
                    'paid_amount' => $paidNet,
                    'balance_due' => $balanceDue,
                    'room' => $room?->number,
                    'guest' => trim(($r->client->first_name ?? '') . ' ' . ($r->client->last_name ?? '')),
                    'total' => round((float) $r->total, 2),
                ];
            });

            return \SuccessData('Reservation calendar', [
                'date_from' => $from,
                'date_to' => $to,
                'events' => $events,
            ]);
        } catch (\Exception $e) {
            return \Failed($e->getMessage());
        }
    }

    public function cancel(CancelReservationRequest $request, int $id)
    {
        try {
            DB::beginTransaction();

            $reservation = Reservation::with('reservationRooms')->findOrFail($id);

            $beforeSnapshot = $this->changeLogService->snapshot($reservation);

            if (Reservation::isCancelled((int) $reservation->reservation_status)) {
                return $this->failedTransaction('Reservation is already cancelled');
            }

            $today = Carbon::today()->toDateString();

            if ($reservation->expire_date < $today) {
                return $this->failedTransaction('Cannot cancel a completed stay.');
            }

            if ((int) $reservation->logedin === 1) {
                return $this->failedTransaction('Cannot cancel while the guest is checked in. Use check-out instead.');
            }

            $reservation->reservation_status = Reservation::STATUS_CANCELLED;
            $reservation->logedin = Reservation::LOGEDIN_NOT_IN_HOUSE;
            $reservation->save();

            $this->roomStatusService->syncForReservation($reservation);

            $afterSnapshot = $this->changeLogService->snapshot($reservation->fresh(['reservationRooms.room', 'payments']));
            $this->changeLogService->recordIfChanged($id, 'cancelled', $beforeSnapshot, $afterSnapshot);

            DB::commit();

            $this->notifyReservationLive($id, 'reservation_cancelled', true);

            return $this->show($id);
        } catch (\Exception $e) {
            DB::rollBack();

            return \Failed($e->getMessage());
        }
    }

    public function extend(ExtendReservationRequest $request, int $id)
    {
        try {
            DB::beginTransaction();

            $reservation = Reservation::with('reservationRooms.room')->findOrFail($id);

            $beforeSnapshot = $this->changeLogService->snapshot($reservation);

            if (Reservation::isCancelled((int) $reservation->reservation_status)) {
                return $this->failedTransaction('Cannot extend a cancelled reservation');
            }

            $today = Carbon::today()->toDateString();

            if ($reservation->expire_date < $today) {
                return $this->failedTransaction('Cannot extend a completed stay.');
            }

            if ($request->expire_date < $today) {
                return $this->failedTransaction('New checkout date cannot be before today.');
            }

            $newExpire = Carbon::parse($request->expire_date)->startOfDay();
            $startDate = Carbon::parse($reservation->start_date)->startOfDay();

            if ($newExpire->lte($startDate)) {
                return $this->failedTransaction('New checkout must be after check-in date');
            }

            foreach ($reservation->reservationRooms as $resRoom) {
                if ($resRoom->room_id && !$this->isRoomAvailable(
                    $resRoom->room_id,
                    $startDate->toDateString(),
                    $newExpire->toDateString(),
                    $reservation->id
                )) {
                    return $this->failedTransaction('Room is not available for the extended period');
                }
            }

            $reservation->expire_date = $newExpire->toDateString();
            $reservation->nights = $startDate->diffInDays($newExpire);

            $priceMode = 0;
            $totalBase = 0.0;
            foreach ($reservation->reservationRooms as $resRoom) {
                if (!$resRoom->room) {
                    continue;
                }
                $lines = $this->pricingEngine->buildDailyBreakdown(
                    $resRoom->room,
                    $startDate->toDateString(),
                    $newExpire->toDateString(),
                    (int) $reservation->rent_type,
                    $priceMode
                );
                $roomBase = $this->pricingEngine->sumBaseAmount($lines);
                $totalBase += $roomBase;

                $this->revenueAccrualService->persistDailyCharges(
                    $reservation->id,
                    $resRoom->id,
                    $resRoom->room_id,
                    (int) $reservation->rent_type,
                    $lines
                );
                $this->accountingPostingService->syncAccrualForReservationRoom($resRoom->id);
            }

            $reservation->base_price = round($totalBase, 2);
            $reservation->subtotal = round(
                $reservation->base_price - $reservation->discount + $reservation->extras + $reservation->penalties,
                2
            );
            $reservation->taxes = round($reservation->subtotal * 0.15, 2, PHP_ROUND_HALF_UP);
            $reservation->total = round($reservation->subtotal + $reservation->taxes, 2);
            $reservation->save();
            $this->roomStatusService->syncForReservation($reservation->fresh(['reservationRooms']));

            $afterSnapshot = $this->changeLogService->snapshot($reservation->fresh(['reservationRooms.room', 'payments']));
            $this->changeLogService->recordIfChanged($id, 'extended', $beforeSnapshot, $afterSnapshot);

            DB::commit();

            $this->notifyReservationLive($id, 'reservation_extended', true);

            return $this->show($id);
        } catch (\Exception $e) {
            DB::rollBack();

            return \Failed($e->getMessage());
        }
    }

    public function shorten(ShortenReservationRequest $request, int $id)
    {
        try {
            $reservation = Reservation::with(['reservationRooms.room', 'payments'])->findOrFail($id);
            $beforeSnapshot = $this->changeLogService->snapshot($reservation);

            $this->shortenService->applyShorten(
                $reservation,
                Carbon::parse($request->expire_date)->startOfDay()
            );

            $afterSnapshot = $this->changeLogService->snapshot($reservation->fresh(['reservationRooms.room', 'payments']));
            $this->changeLogService->recordIfChanged($id, 'shortened', $beforeSnapshot, $afterSnapshot);

            $this->notifyReservationLive($id, 'reservation_shortened', true);

            return $this->show($id);
        } catch (\InvalidArgumentException $e) {
            return \Failed($e->getMessage(), 422);
        } catch (\Exception $e) {
            return \Failed($e->getMessage());
        }
    }

    public function addPayment(AddReservationPaymentRequest $request, int $id)
    {
        try {
            $reservation = Reservation::with('payments')->findOrFail($id);
            $beforeSnapshot = [
                'paid_amount' => round($reservation->paidNetAmount(), 2),
                'balance_due' => round($reservation->balanceDue(), 2),
            ];

            $payment = $this->reservationFinancialService->recordPayment(
                $reservation,
                (float) $request->pay,
                (int) auth()->id(),
                (int) ($request->type ?? ReservationPay::TYPE_PAYMENT)
            );

            $reservation->refresh()->load('payments');
            $afterSnapshot = [
                'paid_amount' => round($reservation->paidNetAmount(), 2),
                'balance_due' => round($reservation->balanceDue(), 2),
                'payment_amount' => round((float) $request->pay, 2),
            ];
            $this->changeLogService->recordIfChanged(
                $id,
                'payment_recorded',
                $beforeSnapshot,
                $afterSnapshot
            );

            return \SuccessData('Payment recorded', $payment);
        } catch (\InvalidArgumentException $e) {
            return \Failed($e->getMessage(), 422);
        } catch (\Exception $e) {
            return \Failed($e->getMessage());
        }
    }

    public function getByClientId(GetByClientIdRequest $request)
    {
        try {
            $perPage = \returnPerPage();
            $clientId = $request->client_id;

            $reservations = Reservation::with(['client', 'reservationRooms.room.roomType', 'payments'])
                ->where('client_id', $clientId)
                ->orderBy('created_at', 'desc')
                ->paginate($perPage);

            return \Pagination($reservations);
        } catch (\Exception $e) {
            return \Failed($e->getMessage());
        }
    }

public function makeReservation(MakeReservationRequest $request)
{
    try {
        DB::beginTransaction();

        $startDate = Carbon::parse($request->start_date)->startOfDay();
        $endDate = Carbon::parse($request->expire_date)->startOfDay();
        $nights = $startDate->diffInDays($endDate);

        $totalBasePrice = 0;
        $roomsData = [];

        $globalPeakMonths = PeakMonth::where('check', 1)->pluck('month_name_en')->toArray();
        $globalPeakDays = PeakDay::where('check', 1)->pluck('day_name_en')->toArray();

        if ($request->has('rooms') && is_array($request->rooms)) {
            foreach ($request->rooms as $roomData) {
                $roomsToProcess = [];

                if (isset($roomData['suite_id']) && $roomData['suite_id']) {
                    $suite = Suite::findOrFail($roomData['suite_id']);
                    $roomsToProcess = $suite->rooms()->where('active', 1)->get();
                } else {
                    $roomsToProcess = [Room::findOrFail($roomData['room_id'])];
                }

                foreach ($roomsToProcess as $room) {
                    if (!$this->isRoomAvailable($room->id, $startDate->toDateString(), $endDate->toDateString())) {
                        throw new \Exception("الغرفة رقم " . ($room->number ?? $room->id) . " غير متاحة في هذه الفترة.");
                    }

                    $priceMode = (int) ($request->price_calculation_mode ?? 0);
                    $dailyLines = $this->pricingEngine->buildDailyBreakdown(
                        $room,
                        $startDate->toDateString(),
                        $endDate->toDateString(),
                        (int) $request->rent_type,
                        $priceMode
                    );
                    $roomPrice = $this->pricingEngine->sumBaseAmount($dailyLines);

                    $totalBasePrice += $roomPrice;
                    $roomsData[] = [
                        'room'        => $room,
                        'suite_id'    => $roomData['suite_id'] ?? null,
                        'price'       => $roomPrice,
                        'daily_lines' => $dailyLines,
                    ];
                }
            }
        }

        $user = auth()->user();
        $discount = $request->discount ?? 0;

        $extras = $request->extras ?? 0;
        $penalties = $request->penalties ?? 0;
        $subtotal = $totalBasePrice - $discount + $extras + $penalties;
$taxes = round($subtotal * 0.15, 2, PHP_ROUND_HALF_UP);        $total = $subtotal + $taxes;

        $payAmount = (float) ($request->pay_amount ?? 0);
        if ($payAmount > 0.005 && $payAmount > $total + 0.005) {
            throw new \InvalidArgumentException('Payment amount exceeds the reservation total.');
        }

        if ((int) ($request->logedin ?? Reservation::LOGEDIN_NOT_IN_HOUSE) === Reservation::LOGEDIN_IN_HOUSE) {
            foreach ($roomsData as $data) {
                $this->roomStatusService->assertRoomReadyForCheckIn($data['room']);
            }
        }

        $reservation = Reservation::create([
            'client_id'             => $request->client_id,
            'start_date'            => $request->start_date,
            'nights'                => $nights,
            'expire_date'           => $request->expire_date,
            'reservation_type'      => $request->reservation_type,
            'reservation_status'    => $request->reservation_status ?? (($request->pay_amount >= $total) ? Reservation::STATUS_CONFIRMED : Reservation::STATUS_PENDING_PAYMENT),
            'stay_reason_id'        => $request->stay_reason_id,
            'reservation_source_id' => $request->reservation_source_id,
            'rent_type'             => $request->rent_type,
            'base_price'            => $totalBasePrice,
            'discount'              => $discount,
            'logedin'              => $request->logedin ?? Reservation::LOGEDIN_NOT_IN_HOUSE,
            'login_time'           => $request->login_time ?? $request->start_date,
            'extras'                => $extras,
            'penalties'             => $penalties,
            'subtotal'              => $subtotal,
            'taxes'                 => $taxes,
            'total'                 => $total,
            'user_id'               => $user->id,
        ]);

        if ($request->filled('pay_amount') && $request->pay_amount > 0) {
            ReservationPay::create([
                'reservation_id' => $reservation->id,
                'pay'            => $request->pay_amount,
                'type'           => $request->pay_type ?? 0,
                'user_id'        => $user->id,
            ]);
            $reservation->load('payments');
            $reservation->syncConfirmationIfFullyPaid();
        }

        $numRooms = count($roomsData);
        foreach ($roomsData as $data) {
            $room = $data['room'];
            $roomType = $room->roomType;

            $roomDiscount = $discount / $numRooms;
            $roomExtras = $extras / $numRooms;
            $roomPenalties = $penalties / $numRooms;
            $finalRoomPrice = ($data['price'] - $roomDiscount + $roomExtras + $roomPenalties) * 1.15;

            $resRoom = ReservationRoom::create([
                'reservation_id' => $reservation->id,
                'room_id'        => $room->id,
                'suite_id'       => $data['suite_id'],
                'price'          => $finalRoomPrice,
            ]);

            $dailyLines = $data['daily_lines'] ?? $this->pricingEngine->buildDailyBreakdown(
                $room,
                $startDate->toDateString(),
                $endDate->toDateString(),
                (int) $request->rent_type,
                (int) ($request->price_calculation_mode ?? 0)
            );
            $this->revenueAccrualService->persistDailyCharges(
                $reservation->id,
                $resRoom->id,
                $room->id,
                (int) $request->rent_type,
                $dailyLines
            );
            $this->accountingPostingService->syncAccrualForReservationRoom($resRoom->id);

            $roomTypePlan = RoomtypePricingplan::where('roomtype_id', $room->room_type_id)
                ->whereHas('pricingplan', function ($q) use ($startDate, $endDate) {
                    $q->where('StartDate', '<=', $endDate->toDateString())
                      ->where('EndDate', '>=', $startDate->toDateString());
                })->first();

            $savedStartPlan = null; $savedEndPlan = null;
            if ($roomTypePlan) {
                $pStart = Carbon::parse($roomTypePlan->pricingplan->StartDate);
                $pEnd = Carbon::parse($roomTypePlan->pricingplan->EndDate);
                $savedStartPlan = $startDate->max($pStart)->toDateString();
                $savedEndPlan = $endDate->min($pEnd)->toDateString();
            }

            $roomPriceData = [
                'reservation_room_id' => $resRoom->id,
                'start_plan'          => $savedStartPlan,
                'end_plan'            => $savedEndPlan,
            ];

            if ($request->rent_type == 0) {
                if ($roomTypePlan) $roomPriceData['pricing_plan_daily'] = $roomTypePlan->DailyPrice;

                if ($roomType->active_type == 0 || $roomType->active_type == 1)
                    $roomPriceData['min_price'] = $roomType->Min_daily_price;
                if ($roomType->active_type == 2 || $roomType->active_type == 1)
                    $roomPriceData['max_price'] = $roomType->Max_daily_price;

            } else {
                if ($roomTypePlan) $roomPriceData['pricing_plan_monthly'] = $roomTypePlan->MonthlyPrice;

                if ($roomType->active_type == 0 || $roomType->active_type == 1)
                    $roomPriceData['min_month'] = $roomType->Min_monthly_price;
                if ($roomType->active_type == 2 || $roomType->active_type == 1)
                    $roomPriceData['max_month'] = $roomType->Max_monthly_price;
            }

            $roomPriceRecord = RoomPrice::create($roomPriceData);

            if ($roomType->active_type == 1) {
                if ($request->rent_type == 0) { // فحص الأيام
                    $period = CarbonPeriod::create($startDate, $endDate->copy()->subDay());
                    foreach ($period as $date) {
                        if (in_array($date->format('l'), $globalPeakDays)) {
                            RoomPriceMaxDay::firstOrCreate([
                                'room_price_id' => $roomPriceRecord->id,
                                'day'           => $date->dayOfWeekIso,
                            ]);
                        }
                    }
                } else {
                    $current = $startDate->copy()->startOfMonth();
                    $final   = $endDate->copy()->startOfMonth();
                    while ($current <= $final) {
                        if (in_array($current->format('F'), $globalPeakMonths)) {
                            RoomPriceMaxMonth::firstOrCreate([
                                'room_price_id' => $roomPriceRecord->id,
                                'month'         => $current->month,
                            ]);
                        }
                        $current->addMonth();
                    }
                }
            }
        }

        $this->roomStatusService->syncForReservation($reservation->fresh(['reservationRooms']));

        DB::commit();

        $this->notifyReservationLive((int) $reservation->id, 'reservation_created', true);

        $reservation->load([
            'client',
            'reservationRooms.room.roomType',
            'reservationRooms.room.building',
            'reservationRooms.room.floor',
            'payments',
            'user',
        ]);

        return \SuccessData('تم إنشاء الحجز بنجاح', $this->reservationDetailPayload($reservation));

    } catch (\InvalidArgumentException $e) {
        DB::rollBack();
        return \Failed($e->getMessage(), 422);
    } catch (\Exception $e) {
        DB::rollBack();
        return \Failed($e->getMessage());
    }
}

    /**
     * Process refund for a reservation using refund policy discount
     */
    public function refund(RefundRequest $request)
    {
        try {
            DB::beginTransaction();
            $reservation = Reservation::with(['payments', 'client', 'reservationRooms'])->findOrFail($request->reservation_id);
            $newExpireDate = $request->input('new_expire_date');

            if ($newExpireDate) {
                $this->shortenService->validateShorten(
                    $reservation,
                    Carbon::parse($newExpireDate)->startOfDay()
                );
            }

            $preview = $this->refundPolicyService->preview($reservation, $newExpireDate);
            $finalRefundAmount = $preview['refund_amount'];
            $policyQuery = $preview['policy'];

            if ($newExpireDate) {
                $this->shortenService->applyShorten(
                    $reservation,
                    Carbon::parse($newExpireDate)->startOfDay()
                );
                $reservation->refresh();
            }

            $refundPay = ReservationPay::create([
                'reservation_id' => $reservation->id,
                'pay' => $finalRefundAmount,
                'type' => ReservationPay::TYPE_REFUND,
                'user_id' => auth()->id(),
            ]);

            $this->accountingPostingService->postPayment($refundPay);

            if (!$newExpireDate) {
                $wasInHouse = (int) $reservation->logedin === Reservation::LOGEDIN_IN_HOUSE;
                $reservation->update([
                    'reservation_status' => Reservation::STATUS_CANCELLED,
                    'logedin' => Reservation::LOGEDIN_NOT_IN_HOUSE,
                ]);
                $this->roomStatusService->releaseRoomsAfterCancellation(
                    $reservation->fresh(['reservationRooms']),
                    $wasInHouse
                );
            }

            DB::commit();

            $this->livePublisher->publishPaymentChanged(
                (int) $request->reservation_id,
                $newExpireDate ? 'partial_refund' : 'refund_processed'
            );
            $this->notifyReservationLive((int) $request->reservation_id, 'reservation_refunded');

            $reservation = Reservation::with([
                'client',
                'reservationRooms.room.roomType',
                'reservationRooms.room.building',
                'reservationRooms.room.floor',
                'payments',
                'user',
            ])->findOrFail($request->reservation_id);

            return \SuccessData('Refund processed successfully', array_merge(
                $this->reservationDetailPayload($reservation),
                [
                    'refund_id' => $refundPay->id,
                    'amount' => $finalRefundAmount,
                    'policy_name' => $policyQuery->name,
                    'breakdown' => $preview['breakdown'],
                    'partial_cancel' => $newExpireDate !== null,
                ]
            ));
        } catch (RefundNotAllowedException $e) {
            DB::rollBack();

            return \Failed($e->getMessage(), 422);
        } catch (\InvalidArgumentException $e) {
            DB::rollBack();

            return \Failed($e->getMessage(), 422);
        } catch (\Exception $e) {
            DB::rollBack();

            return \Failed($e->getMessage());
        }
    }

    /**
     * NEW: Check if room is available for given dates
     */
private function isRoomAvailable($roomId, $startDate, $endDate, ?int $excludeReservationId = null)
    {
        $room = Room::where('id', $roomId)
            ->where('active', 1)
            ->first();
        if (!$room) {
            return false;
        }

        if ($this->roomStatusService->roomHasActiveInHouseStay((int) $roomId, $excludeReservationId)) {
            return false;
        }

        $query = ReservationRoom::where('room_id', $roomId)
            ->whereHas('reservation', function ($q) use ($startDate, $endDate, $excludeReservationId) {
                $q->whereNotIn('reservation_status', Reservation::nonBlockingInventoryStatuses())
                    ->where('start_date', '<', $endDate)
                    ->where('expire_date', '>', $startDate);
                if ($excludeReservationId) {
                    $q->where('id', '!=', $excludeReservationId);
                }
            });

        return !$query->exists();
    }


    private function calculateRoomPrice($room, $start, $end, $rentType, $priceMode = 0)
    {
        $startDate = Carbon::parse($start);
        $endDate = Carbon::parse($end);

        $roomType = RoomType::find($room->room_type_id);
        $roomTypePlan = RoomtypePricingplan::with(['pricingplan', 'roomType'])->where('roomtype_id', $room->room_type_id)
            ->whereHas('pricingplan', function ($query) use ($start, $end) {
                $query->where('StartDate', '<=', $end)
                    ->where('EndDate', '>=', $start);
            })->first();

        $totalPrice = 0;

        if ($rentType == 0) {
            $nights = $startDate->diffInDays($endDate);

            switch ($priceMode) {
                case 0:
                    if ($roomTypePlan) {
                        $planStart = Carbon::parse($roomTypePlan->pricingplan->StartDate);
                        $planEnd = Carbon::parse($roomTypePlan->pricingplan->EndDate);

                        if ($start >= $planStart->toDateString() && $end <= $planEnd->toDateString()) {
                            $totalPrice = $roomTypePlan->DailyPrice * $nights;
                        } else {
                            switch ($roomTypePlan->pricingplan->ActiveType) {
                                case 0: // Const
                                    $totalPrice = $roomTypePlan->roomType->Min_daily_price * $nights;
                                    break;
                                case 1: // As Per Day
                                    $dailyPrices = [];
                                    for ($date = $startDate->copy(); $date->lt($endDate); $date->addDay()) {
                                        if ($date->between($planStart, $planEnd)) {
                                            $dailyPrices[] = $roomTypePlan->DailyPrice;
                                        } else {
                                            $dayName = $date->format('l');
                                            $dailyPrices[] = $this->checkPeakDay($dayName)
                                                ? $roomTypePlan->roomType->Max_daily_price
                                                : $roomTypePlan->roomType->Min_daily_price;
                                        }
                                    }
                                    $totalPrice = array_sum($dailyPrices);
                                    break;
                                case 2: // Plan Price
                                    $totalPrice = $roomTypePlan->DailyPrice * $nights;
                                    break;
                            }
                        }
                    } else {
                        switch ($roomType->active_type) {
                            case 0:
                                $totalPrice = $roomType->Min_daily_price * $nights;
                                break;
                            case 1:
                                $dailyPrices = [];
                                for ($date = $startDate->copy(); $date->lt($endDate); $date->addDay()) {
                                    $dayName = $date->format('l');
                                    $dailyPrices[] = $this->checkPeakDay($dayName)
                                        ? $roomType->Max_daily_price
                                        : $roomType->Min_daily_price;
                                }
                                $totalPrice = array_sum($dailyPrices);
                                break;
                            case 2:
                                $totalPrice = $roomType->Max_daily_price * $nights;
                                break;
                        }
                    }
                    break;

                case 1: // Pricing plan only
                    if ($roomTypePlan) {
                        $totalPrice = $roomTypePlan->DailyPrice * $nights;
                    } else {
                        throw new \Exception('No pricing plan found for price mode 1');
                    }
                    break;
                case 2: // RoomType only
                    switch ($roomType->active_type) {
                        case 0:
                            $totalPrice = $roomType->Min_daily_price * $nights;
                            break;
                        case 1:
                            $dailyPrices = [];
                            for ($date = $startDate->copy(); $date->lt($endDate); $date->addDay()) {
                                $dayName = $date->format('l');
                                $dailyPrices[] = $this->checkPeakDay($dayName)
                                    ? $roomType->Max_daily_price
                                    : $roomType->Min_daily_price;
                            }
                            $totalPrice = array_sum($dailyPrices);
                            break;
                        case 2:
                            $totalPrice = $roomType->Max_daily_price * $nights;
                            break;
                    }
                    break;
            }
        }
        elseif ($rentType == 1) {

            $daysInPeriod = $startDate->diffInDays($endDate, false);

            switch ($priceMode) {
           case 0:
            $monthlyMin = $roomType->Min_monthly_price;
            $monthlyMax = $roomType->Max_monthly_price;
            $dailyMin   = $roomType->Min_daily_price;
            $dailyMax   = $roomType->Max_daily_price;

            $totalPrice = 0;
            $startDate = Carbon::parse($start);
            $endDate = Carbon::parse($end);

            $totalFullMonths = $startDate->diffInMonths($endDate);

            $tempDate = $startDate->copy();
            for ($m = 0; $m < $totalFullMonths; $m++) {
                $nextMonthDate = $tempDate->copy()->addMonthNoOverflow();
                $chunkDays = $tempDate->diffInDays($nextMonthDate);
                $chunkPrice = 0;

                for ($i = 0; $i < $chunkDays; $i++) {
                    $currDay = $tempDate->copy()->addDays($i + 1);
                    $mName = $currDay->format('F');

                    $monthlyValueForThisDay = $this->checkPeakMonth($mName) ? $monthlyMax : $monthlyMin;
                    $chunkPrice += ($monthlyValueForThisDay / $chunkDays);
                }

                $totalPrice += $chunkPrice;
                $tempDate = $nextMonthDate;
            }

            $remainingDays = $tempDate->diffInDays($endDate);

            if ($remainingDays > 0) {
                $extraPartPrice = 0;
                for ($d = 0; $d < $remainingDays; $d++) {
                    $currDay = $tempDate->copy()->addDays($d + 1);
                    $extraPartPrice += $this->checkPeakDay($currDay->format('l')) ? $dailyMax : $dailyMin;
                }

                $potentialNextMonth = $tempDate->copy()->addMonthNoOverflow();
                $potentialDays = $tempDate->diffInDays($potentialNextMonth);
                $potentialMonthPrice = 0;

                for ($i = 0; $i < $potentialDays; $i++) {
                    $currDay = $tempDate->copy()->addDays($i + 1);
                    $mName = $currDay->format('F');
                    $monthlyValueForThisDay = $this->checkPeakMonth($mName) ? $monthlyMax : $monthlyMin;
                    $potentialMonthPrice += ($monthlyValueForThisDay / $potentialDays);
                }

                if ($extraPartPrice > $potentialMonthPrice) {
                    $totalPrice += $potentialMonthPrice;
                } else {
                    $totalPrice += $extraPartPrice;
                }
            }

            $totalPrice = round($totalPrice, 2, PHP_ROUND_HALF_UP);
            if (abs($totalPrice - round($totalPrice, 0)) < 0.005) {
                $totalPrice = round($totalPrice, 0);
            }
            break;

    $totalPrice = $monthlyTotalPart;

    $extraDays = $tempDate->diffInDays($endDate);

    if ($extraDays > 0) {
        for ($d = 0; $d < $extraDays; $d++) {
            $extraDate = $tempDate->copy()->addDays($d+1);
            $dayName = $extraDate->format('l');

            $inPlan = false;
            if ($roomTypePlan && $planStart && $planEnd) {
                if ($extraDate->betweenIncluded($planStart, $planEnd)) {
                    $inPlan = true;
                }
            }

            if ($inPlan) {
                $extraPart += ($planPrice / $extraDate->daysInMonth);
            } else {
                $extraPart += $this->checkPeakDay($dayName) ? $dailyMax : $dailyMin;
            }
        }
        $totalPrice += $extraPart;
    }

    $totalPrice = round($totalPrice, 2, PHP_ROUND_HALF_UP);
    if (abs($totalPrice - round($totalPrice, 0)) < 0.01) {
        $totalPrice = round($totalPrice, 0);
    }
    break;

                case 1: // Plan only
                    if ($roomTypePlan) {
                        $totalPrice = round($roomTypePlan->MonthlyPrice + ($roomTypePlan->MonthlyPrice * max(0, ($daysInPeriod - $startDate->daysInMonth))) / $endDate->daysInMonth, 2, PHP_ROUND_HALF_UP);
                        if (abs($totalPrice - round($totalPrice, 0)) < 0.005) {
                            $totalPrice = round($totalPrice, 0);
                        }
                    } else {
                        throw new \Exception('No pricing plan for mode 1');
                    }
                    break;

case 2:
  $monthlyMin = $roomType->Min_monthly_price;
    $monthlyMax = $roomType->Max_monthly_price;
    $dailyMin = $roomType->Min_daily_price;
    $dailyMax = $roomType->Max_daily_price;
    $totalPrice = 0;
    $extraPart = 0;
    $fullMonths = 0;
    $monthlyTotalPart = 0;

    $tempDate = $startDate->copy();

    $targetDay = $startDate->day;

    while (true) {
        $nextMonth = $tempDate->copy()->addMonthNoOverflow();

        if ($startDate->isLastOfMonth()) {
            $potentialNext = $nextMonth->copy()->day($nextMonth->daysInMonth);
        } else {
            $potentialNext = $nextMonth->copy()->day(min($targetDay, $nextMonth->daysInMonth));
        }

        if ($potentialNext->lte($endDate)) {
            $currentMonthPrice = 0;
            switch ($roomType->active_type) {
                case 0:
                    $currentMonthPrice = $monthlyMin;
                    break;
                case 2:
                    $currentMonthPrice = $monthlyMax;
                    break;
                case 1:
                    $chunkDays = $tempDate->diffInDays($potentialNext);
                    $chunkPrice = 0;

                    for ($i = 0; $i < $chunkDays; $i++) {
                        $currDay = $tempDate->copy()->addDays($i+1);
                        $mName = $currDay->format('F');
                        $monthlyValueForThisDay = $this->checkPeakMonth($mName) ? $monthlyMax : $monthlyMin;

                        $chunkPrice += ($monthlyValueForThisDay / $chunkDays);
                    }
                    $currentMonthPrice = $chunkPrice;
                    break;
            }

            $monthlyTotalPart += $currentMonthPrice;
            $fullMonths++;
            $tempDate = $potentialNext;

            if ($tempDate->eq($endDate)) break;
        } else {
            break;
        }
    }

    $totalPrice = $monthlyTotalPart;

    $extraDays = $tempDate->diffInDays($endDate);

    if ($extraDays > 0) {
        for ($d = 0; $d < $extraDays; $d++) {
            $extraDate = $tempDate->copy()->addDays($d+1);
            $dayName = $extraDate->format('l');
            $extraPart += $this->checkPeakDay($dayName) ? $dailyMax : $dailyMin;
        }
        $totalPrice += $extraPart;
    }


    $totalPrice = round($totalPrice, 2, PHP_ROUND_HALF_UP);
    if (abs($totalPrice - round($totalPrice, 0)) < 0.005) {
        $totalPrice = round($totalPrice, 0);
    }
            }}
    return $totalPrice;
    }

    public  function checkReservation(CheckReservationRequest $request)
    {
        try {
            $perPage = \returnPerPage();
            $availableRoomsQuery = Room::where('building_id', '=', $request->building_id)
                ->whereNotIn('roomStatus', [2, 4])
                ->where('active', '=', 1);
            if ($request->filled('room_type')) {
                $availableRoomsQuery->where('room_type_id', '=', $request->room_type);
            }
            if ($request->filled('suite_id')) {
                $availableRoomsQuery->where('suite_id', '=', $request->suite_id);
            }
            if ($request->filled('floor_id')) {
                $availableRoomsQuery->where('floor_id', '=', $request->floor_id);
            }
            $availableRoomsQuery->whereDoesntHave('reservationRooms.reservation', function ($reservationQuery) use ($request) {
                $reservationQuery->whereNotIn('reservation_status', Reservation::nonBlockingInventoryStatuses())
                    ->where('start_date', '<', $request->expire_date)
                    ->where('expire_date', '>', $request->start_date);
            });
            //  $availableRooms = AvailableRoomResource::collection($availableRoomsQuery->paginate($perPage));
             $availableRooms = $availableRoomsQuery->paginate($perPage);
            return \Pagination($availableRooms);
        } catch (\Exception $e) {
            return \Failed($e->getMessage());
        }
    }

    /**
     * All rooms in a building with availability for a check-in / check-out range.
     */
    public function bookingRoomAvailability(BookingRoomAvailabilityRequest $request)
    {
        try {
            $startDate = $request->start_date;
            $endDate = $request->expire_date;

            $query = Room::with(['roomType', 'floor', 'suite'])
                ->where('building_id', $request->building_id)
                ->where('active', 1);

            if ($request->filled('floor_id')) {
                $query->where('floor_id', $request->floor_id);
            }
            if ($request->filled('room_type_id')) {
                $query->where('room_type_id', $request->room_type_id);
            }
            if ($request->filled('search')) {
                $query->where('number', 'like', '%' . trim($request->search) . '%');
            }

            $rooms = $query->orderBy('floor_id')->orderBy('number')->get();
            $roomIds = $rooms->pluck('id')->all();

            $conflictsByRoom = collect();
            $inHouseByRoom = collect();
            if (!empty($roomIds)) {
                $conflictsByRoom = ReservationRoom::whereIn('room_id', $roomIds)
                    ->whereHas('reservation', function ($q) use ($startDate, $endDate) {
                        $q->whereNotIn('reservation_status', Reservation::nonBlockingInventoryStatuses())
                            ->where('start_date', '<', $endDate)
                            ->where('expire_date', '>', $startDate);
                    })
                    ->with(['reservation.client'])
                    ->get()
                    ->groupBy('room_id')
                    ->map(fn ($rows) => $rows->first());

                $inHouseByRoom = ReservationRoom::whereIn('room_id', $roomIds)
                    ->whereHas('reservation', function ($q) {
                        $q->where('reservation_status', Reservation::STATUS_CONFIRMED)
                            ->where('logedin', Reservation::LOGEDIN_IN_HOUSE);
                    })
                    ->with(['reservation.client'])
                    ->get()
                    ->groupBy('room_id')
                    ->map(fn ($rows) => $rows->first());
            }

            $payload = $rooms->map(function (Room $room) use ($conflictsByRoom, $inHouseByRoom) {
                $inHouseRow = $inHouseByRoom->get($room->id);
                $conflictRow = $conflictsByRoom->get($room->id);
                $reservation = $inHouseRow?->reservation ?? $conflictRow?->reservation;

                $unavailableReason = null;
                $availableForPeriod = true;
                $needsCleaningBeforeCheckin = (int) $room->roomStatus === 3;

                if ($inHouseRow) {
                    $availableForPeriod = false;
                    $unavailableReason = 'occupied';
                } elseif ((int) $room->roomStatus === 2) {
                    $this->roomStatusService->setAvailableIfNoActiveStay((int) $room->id);
                    $room->refresh();
                }

                if ((int) $room->roomStatus === 4) {
                    $availableForPeriod = false;
                    $unavailableReason = 'out_of_service';
                } elseif ($conflictRow && !$inHouseRow) {
                    $availableForPeriod = false;
                    $unavailableReason = 'booked';
                }

                $client = $reservation?->client;
                $clientName = $client
                    ? trim(($client->first_name ?? '') . ' ' . ($client->last_name ?? ''))
                    : null;

                return [
                    'id' => $room->id,
                    'number' => $room->number,
                    'room_type_id' => $room->room_type_id,
                    'floor_id' => $room->floor_id,
                    'roomStatus' => (int) $room->roomStatus,
                    'room_type' => $room->roomType ? [
                        'id' => $room->roomType->id,
                        'name_en' => $room->roomType->name_en ?? null,
                        'name_ar' => $room->roomType->name_ar ?? null,
                    ] : null,
                    'floor' => $room->floor ? [
                        'id' => $room->floor->id,
                        'number' => $room->floor->number ?? null,
                        'name' => $room->floor->name ?? null,
                    ] : null,
                    'suite_id' => $room->suite_id,
                    'suite' => $room->suite ? [
                        'id' => $room->suite->id,
                        'number' => $room->suite->number ?? null,
                    ] : null,
                    'available_for_period' => $availableForPeriod,
                    'unavailable_reason' => $unavailableReason,
                    'needs_cleaning_before_checkin' => $needsCleaningBeforeCheckin,
                    'conflict' => $reservation ? [
                        'reservation_id' => $reservation->id,
                        'start_date' => $reservation->start_date,
                        'expire_date' => $reservation->expire_date,
                        'client_name' => $clientName ?: null,
                    ] : null,
                ];
            });

            $availableCount = $payload->where('available_for_period', true)->count();

            return SuccessData('Room availability loaded.', [
                'rooms' => $payload->values(),
                'summary' => [
                    'total' => $payload->count(),
                    'available' => $availableCount,
                    'unavailable' => $payload->count() - $availableCount,
                    'start_date' => $startDate,
                    'expire_date' => $endDate,
                ],
            ]);
        } catch (\Exception $e) {
            return Failed($e->getMessage());
        }
    }

    public function getRoomPrice(GetRoomPriceRequest $request)
    {
        try {
            $startDate = Carbon::parse($request->startDate)->startOfDay();
            $priceMode = (int) ($request->input('price_calculation_mode', 0));

            if ((int) $request->typeReservation === 0) {
                $endDate = Carbon::parse($request->endDate)->startOfDay();
                $rentType = 0;
            } else {
                $rentType = 1;
                if ($request->filled('endDate')) {
                    $endDate = Carbon::parse($request->endDate)->startOfDay();
                } else {
                    $months = max(1, (int) ($request->numberOfMonths ?? 1));
                    $endDate = $startDate->copy()->addMonthsNoOverflow($months);
                }
            }

            $room = $this->representativeRoomForType((int) $request->roomTypeId);
            $startStr = $startDate->toDateString();
            $endStr = $endDate->toDateString();
            $stayPlan = ($priceMode === 0 || $priceMode === 1)
                ? $this->pricingEngine->resolveStayPricingPlan((int) $request->roomTypeId, $startStr, $endStr)
                : null;

            $lines = $this->pricingEngine->buildDailyBreakdown(
                $room,
                $startDate->toDateString(),
                $endDate->toDateString(),
                $rentType,
                $priceMode
            );

            $days = $this->pricingEngine->linesToDaysMap($lines);
            $totalPrice = $this->pricingEngine->sumBaseAmount($lines);
            $segments = $this->pricingEngine->buildPriceSegments($lines, $rentType);
            $appliedPlan = $this->formatAppliedPlanMeta($stayPlan, $priceMode);

            if ($rentType === 0) {
                return \SuccessData('Daily pricing calculated', [
                    'days' => $days,
                    'segments' => $segments,
                    'totalPrice' => $totalPrice,
                    'nightCount' => count($lines),
                    'rent_type' => $rentType,
                    'applied_plan' => $appliedPlan,
                ]);
            }

            return \SuccessData('Monthly pricing calculated', [
                'startDate' => $startDate->toDateString(),
                'endDate' => $endDate->toDateString(),
                'numberOfMonths' => $request->numberOfMonths,
                'days' => $days,
                'segments' => $segments,
                'totalPrice' => $totalPrice,
                'rent_type' => $rentType,
                'nightCount' => count($lines),
                'applied_plan' => $appliedPlan,
            ]);
        } catch (\Exception $e) {
            return \Failed($e->getMessage());
        }
    }

    private function formatAppliedPlanMeta(?RoomtypePricingplan $stayPlan, int $priceMode): ?array
    {
        if (!$stayPlan || !$stayPlan->pricingplan) {
            return null;
        }

        $plan = $stayPlan->pricingplan;

        return [
            'id' => $stayPlan->id,
            'pricingplan_id' => $plan->id,
            'name_en' => $plan->NameEn,
            'name_ar' => $plan->NameAr,
            'start_date' => $plan->StartDate,
            'end_date' => $plan->EndDate,
            'daily_price' => (float) $stayPlan->DailyPrice,
            'monthly_price' => (float) $stayPlan->MonthlyPrice,
            'price_mode' => $priceMode,
        ];
    }

    private function representativeRoomForType(int $roomTypeId): Room
    {
        $room = Room::where('room_type_id', $roomTypeId)
            ->where('active', 1)
            ->orderBy('id')
            ->first();

        if (!$room) {
            throw new \Exception('No active room found for this room type.');
        }

        return $room;
    }

    public function checkPeakDay($day): bool
    {
        return PeakDay::where('day_name_en', $day)->value('check') == 1;
    }

    public function checkPeakMonth($Month): bool
    {
        return PeakMonth::where('month_name_en', $Month)->value('check') == 1;
    }

    private function recalculateReservationPricing(Reservation $reservation): void
    {
        $priceMode = (int) ($reservation->price_calculation_mode ?? 0);
        $startDate = Carbon::parse($reservation->start_date)->startOfDay();
        $endDate = Carbon::parse($reservation->expire_date)->startOfDay();
        $reservation->nights = $startDate->diffInDays($endDate);

        $totalBase = 0.0;
        foreach ($reservation->reservationRooms as $resRoom) {
            if (!$resRoom->room) {
                continue;
            }
            $lines = $this->pricingEngine->buildDailyBreakdown(
                $resRoom->room,
                $startDate->toDateString(),
                $endDate->toDateString(),
                (int) $reservation->rent_type,
                $priceMode
            );
            $roomBase = $this->pricingEngine->sumBaseAmount($lines);
            $totalBase += $roomBase;

            $this->revenueAccrualService->persistDailyCharges(
                $reservation->id,
                $resRoom->id,
                $resRoom->room_id,
                (int) $reservation->rent_type,
                $lines
            );
            $this->accountingPostingService->syncAccrualForReservationRoom($resRoom->id);
        }

        $reservation->base_price = round($totalBase, 2);
        $reservation->subtotal = round(
            $reservation->base_price - $reservation->discount + $reservation->extras + $reservation->penalties,
            2
        );
        $reservation->taxes = round($reservation->subtotal * 0.15, 2, PHP_ROUND_HALF_UP);
        $reservation->total = round($reservation->subtotal + $reservation->taxes, 2);
        $this->reservationFinancialService->syncTotalsFromDailyCharges($reservation, preservePaidInFull: true);
    }

    private function resolveCalendarState(Reservation $r, float $paidNet, float $balanceDue): string
    {
        if (Reservation::isCancelled((int) $r->reservation_status)) {
            return 'cancelled';
        }

        if ((int) $r->reservation_status === Reservation::STATUS_PENDING_PAYMENT) {
            return $paidNet > 0 ? 'partial-pay' : 'needs-pay';
        }

        if ((int) $r->logedin === 1) {
            return 'checked-in';
        }

        if ($balanceDue <= 0.01) {
            return 'confirmed';
        }

        if ($paidNet > 0) {
            return 'partial-pay';
        }

        return 'needs-pay';
    }

    public function getReservationByDate(GetReservationByDateRequest $request)
    {
        try {
            $query = Reservation::with(['client', 'reservationRooms.room.roomType', 'payments'])
                ->where('start_date', '<=', $request->expire_date)
                ->where('expire_date', '>=', $request->start_date);

            $query->whereHas('reservationRooms.room', function ($roomQuery) use ($request) {
                if ($request->has('building_id') && $request->building_id) {
                    $roomQuery->where('building_id', $request->building_id);
                }
                if ($request->has('floor_id') && $request->floor_id) {
                    $roomQuery->where('floor_id', $request->floor_id);
                }
                if ($request->has('suite_id') && $request->suite_id) {
                    $roomQuery->where('suite_id', $request->suite_id);
                }
            });
            $reservations = $query->get();
            if ($reservations->isEmpty()) {
                return SuccessData('No reservations found', []);
            }
            return SuccessData('Reservations found', $reservations);
        } catch (\Exception $e) {
            return Failed($e->getMessage());
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function reservationDetailPayload(Reservation $reservation): array
    {
        $dailyCharges = ReservationDailyCharge::where('reservation_id', $reservation->id)
            ->orderBy('charge_date')
            ->get();

        $roomNumbers = $reservation->reservationRooms
            ->map(fn ($row) => $row->room?->number)
            ->filter(fn ($n) => $n !== null && $n !== '')
            ->values()
            ->all();

        return [
            'reservation' => $reservation,
            'daily_charges' => $dailyCharges,
            'paid_amount' => $reservation->paidNetAmount(),
            'balance_due' => $reservation->balanceDue(),
            'room_numbers' => $roomNumbers,
            'room_numbers_label' => $roomNumbers !== [] ? implode(', ', array_map('strval', $roomNumbers)) : null,
            'change_logs' => $this->changeLogService->listForReservation((int) $reservation->id),
        ];
    }
}
