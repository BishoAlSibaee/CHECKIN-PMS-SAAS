<?php

namespace App\Services\Accounting;

use App\Models\ChartOfAccount;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\PosCategory;
use App\Models\PosCharge;
use App\Models\Reservation;
use App\Models\ReservationDailyCharge;
use App\Models\ReservationPay;
use App\Models\ReservationRoom;
use App\Models\Reservation_source;
use App\Models\Room;
use App\Services\HotelResolverService;
use Carbon\Carbon;

class AccountingPostingService
{
    private const TAX_RATE = 0.15;

    public function __construct(private HotelResolverService $hotelResolver) {}

    public function createManualEntry(array $payload, ?int $userId, int $hotelId): JournalEntry
    {
        $entry = JournalEntry::create([
            'hotel_id' => $hotelId,
            'entry_date' => $payload['entry_date'],
            'reference' => $payload['reference'] ?? null,
            'description' => $payload['description'] ?? null,
            'user_id' => $userId,
            'source_type' => 'manual',
        ]);

        foreach ($payload['lines'] as $line) {
            JournalEntryLine::create([
                'journal_entry_id' => $entry->id,
                'account_id' => $line['account_id'],
                'debit' => $line['debit'] ?? 0,
                'credit' => $line['credit'] ?? 0,
                'memo' => $line['memo'] ?? null,
            ]);
        }

        return $entry->load('lines');
    }

    public function postPayment(ReservationPay $payment): ?JournalEntry
    {
        $ref = 'PAY-'.$payment->id;
        $isPayment = (int) $payment->type === ReservationPay::TYPE_PAYMENT;
        $existing = JournalEntry::where('reference', $ref)->first();
        if ($existing) {
            if (! $isPayment && $this->shouldRepostLegacyRefundEntry($existing)) {
                $existing->lines()->delete();
                $existing->delete();
            } else {
                return $existing;
            }
        }

        $cash = ChartOfAccount::where('code', '1010')->first();
        if (! $cash) {
            return null;
        }

        $amount = (float) $payment->pay;
        if ($amount <= 0) {
            return null;
        }

        if ($isPayment) {
            $ar = ChartOfAccount::where('code', '1100')->first();
            if (! $ar) {
                return null;
            }

            $lines = [
                ['account_id' => $cash->id, 'debit' => $amount, 'credit' => 0],
                ['account_id' => $ar->id, 'debit' => 0, 'credit' => $amount],
            ];
        } else {
            $ar = ChartOfAccount::where('code', '1100')->first();
            if (! $ar) {
                return null;
            }

            $lines = [
                ['account_id' => $ar->id, 'debit' => $amount, 'credit' => 0],
                ['account_id' => $cash->id, 'debit' => 0, 'credit' => $amount],
            ];
        }

        $reservation = $payment->reservation ?? Reservation::find($payment->reservation_id);
        $hotelId = $reservation !== null ? $this->hotelResolver->forReservation($reservation) : $this->hotelResolver->defaultHotelId();

        $entry = JournalEntry::create([
            'hotel_id' => $hotelId,
            'entry_date' => $payment->created_at?->toDateString() ?? now()->toDateString(),
            'reference' => $ref,
            'description' => $isPayment ? 'Guest payment' : 'Guest refund',
            'user_id' => $payment->user_id,
            'source_type' => 'reservation_pay',
            'source_id' => $payment->id,
        ]);

        foreach ($lines as $line) {
            JournalEntryLine::create([
                'journal_entry_id' => $entry->id,
                'account_id' => $line['account_id'],
                'debit' => $line['debit'] ?? 0,
                'credit' => $line['credit'] ?? 0,
                'reservation_id' => $payment->reservation_id,
                'memo' => $isPayment ? 'Payment' : 'Refund',
            ]);
        }

        return $entry->load('lines');
    }

    private function shouldRepostLegacyRefundEntry(JournalEntry $entry): bool
    {
        $refunds = ChartOfAccount::where('code', '5020')->first();
        if (! $refunds) {
            return false;
        }

        return $entry->lines()->where('account_id', $refunds->id)->exists();
    }

    /**
     * Post accrual journal for one daily charge (Dr AR / Cr Revenue / Cr VAT).
     */
    public function postDailyChargeAccrual(ReservationDailyCharge $charge): ?JournalEntry
    {
        $reservation = $charge->reservation ?? Reservation::find($charge->reservation_id);
        if (! $reservation || (int) $reservation->reservation_status !== 1) {
            return null;
        }

        $ref = $this->uniqueAccrualReference($charge);
        $existing = JournalEntry::query()
            ->where('source_type', 'daily_charge')
            ->where('source_id', $charge->id)
            ->first();
        if ($existing) {
            return $existing;
        }

        $amounts = $this->allocateChargeAmounts($charge, $reservation);
        if ($amounts['revenue'] <= 0) {
            return null;
        }

        $ar = ChartOfAccount::where('code', '1100')->first();
        $revenue = ChartOfAccount::where('code', '4010')->first();
        $vat = ChartOfAccount::where('code', '2150')->first();
        if (! $ar || ! $revenue || ! $vat) {
            return null;
        }

        $entry = JournalEntry::create([
            'hotel_id' => $this->hotelResolver->forRoomId($charge->room_id),
            'entry_date' => $charge->charge_date->toDateString(),
            'reference' => $ref,
            'description' => 'Accrual revenue — reservation #'.$charge->reservation_id,
            'user_id' => null,
            'source_type' => 'daily_charge',
            'source_id' => $charge->id,
        ]);

        JournalEntryLine::create([
            'journal_entry_id' => $entry->id,
            'account_id' => $ar->id,
            'debit' => $amounts['revenue'],
            'credit' => 0,
            'reservation_id' => $charge->reservation_id,
            'memo' => 'AR accrual',
        ]);

        if ($amounts['subtotal'] > 0) {
            JournalEntryLine::create([
                'journal_entry_id' => $entry->id,
                'account_id' => $revenue->id,
                'debit' => 0,
                'credit' => $amounts['subtotal'],
                'reservation_id' => $charge->reservation_id,
                'memo' => 'Room revenue',
            ]);
        }

        if ($amounts['tax'] > 0) {
            JournalEntryLine::create([
                'journal_entry_id' => $entry->id,
                'account_id' => $vat->id,
                'debit' => 0,
                'credit' => $amounts['tax'],
                'reservation_id' => $charge->reservation_id,
                'memo' => 'VAT',
            ]);
        }

        return $entry->load('lines');
    }

    public function syncAccrualForReservation(int $reservationId): int
    {
        $chargeIds = ReservationDailyCharge::where('reservation_id', $reservationId)->pluck('id');
        $roomIds = ReservationDailyCharge::where('reservation_id', $reservationId)
            ->distinct()
            ->pluck('reservation_room_id')
            ->filter();

        $entryIds = JournalEntry::query()
            ->where('source_type', 'daily_charge')
            ->where(function ($query) use ($chargeIds, $reservationId) {
                if ($chargeIds->isNotEmpty()) {
                    $query->whereIn('source_id', $chargeIds);
                }
                $query->orWhereIn('id', function ($sub) use ($reservationId) {
                    $sub->select('journal_entry_id')
                        ->from('journal_entry_lines')
                        ->where('reservation_id', $reservationId);
                });
            })
            ->pluck('id');

        JournalEntry::query()
            ->whereIn('id', $entryIds)
            ->each(function (JournalEntry $entry) {
                $entry->lines()->delete();
                $entry->delete();
            });

        foreach ($roomIds as $roomId) {
            $legacyPrefix = 'ACCRUAL-RR'.$roomId.'-';
            JournalEntry::where('reference', 'like', $legacyPrefix.'%')->each(function (JournalEntry $entry) {
                $entry->lines()->delete();
                $entry->delete();
            });
        }

        $charges = ReservationDailyCharge::where('reservation_id', $reservationId)
            ->with('reservation')
            ->orderBy('charge_date')
            ->orderBy('id')
            ->get();

        $count = 0;
        foreach ($charges as $charge) {
            if ($this->postDailyChargeAccrual($charge)) {
                $count++;
            }
        }

        return $count;
    }

    public function syncAccrualForReservationRoom(int $reservationRoomId): int
    {
        $reservationId = ReservationRoom::query()->whereKey($reservationRoomId)->value('reservation_id');
        if (! $reservationId) {
            return 0;
        }

        return $this->syncAccrualForReservation((int) $reservationId);
    }

    public function backfillAllAccruals(): int
    {
        JournalEntry::query()
            ->where(function ($query) {
                $query->where('source_type', 'daily_charge')
                    ->orWhere('reference', 'like', 'ACCRUAL-RR%');
            })
            ->each(function (JournalEntry $entry) {
                $entry->lines()->delete();
                $entry->delete();
            });

        $count = 0;
        ReservationDailyCharge::query()
            ->join('reservations', 'reservation_daily_charges.reservation_id', '=', 'reservations.id')
            ->where('reservations.reservation_status', 1)
            ->select('reservation_daily_charges.*')
            ->orderBy('reservation_daily_charges.id')
            ->chunk(200, function ($charges) use (&$count) {
                foreach ($charges as $charge) {
                    if ($this->postDailyChargeAccrual($charge)) {
                        $count++;
                    }
                }
            });

        return $count;
    }

    /**
     * @return array{subtotal: float, tax: float, revenue: float}
     */
    public function allocateChargeAmounts(ReservationDailyCharge $charge, Reservation $reservation): array
    {
        $reservationTotalBase = (float) ReservationDailyCharge::where('reservation_id', $reservation->id)
            ->sum('base_amount');

        $weight = $reservationTotalBase > 0
            ? (float) $charge->base_amount / $reservationTotalBase
            : 0;

        $periodSubtotal = (float) $charge->base_amount
            - (float) $reservation->classification_discount * $weight
            - (float) $reservation->discount * $weight
            + (float) $reservation->extras * $weight
            + (float) $reservation->penalties * $weight;

        $periodTax = round($periodSubtotal * self::TAX_RATE, 2);
        $periodRevenue = round($periodSubtotal + $periodTax, 2);

        return [
            'subtotal' => round($periodSubtotal, 2),
            'tax' => $periodTax,
            'revenue' => $periodRevenue,
        ];
    }

    public function accrualReference(ReservationDailyCharge $charge): string
    {
        $date = $charge->charge_date instanceof \Carbon\Carbon
            ? $charge->charge_date->format('Y-m-d')
            : (string) $charge->charge_date;

        $roomNumber = $this->resolveRoomNumberForCharge($charge);
        if ($roomNumber !== null && $roomNumber !== '') {
            return 'ACCRUAL-'.$roomNumber.'-'.$date;
        }

        return 'ACCRUAL-RR'.$charge->reservation_room_id.'-'.$date;
    }

    private function uniqueAccrualReference(ReservationDailyCharge $charge): string
    {
        $base = $this->accrualReference($charge);
        $conflict = JournalEntry::where('reference', $base)->first();
        if (! $conflict || (int) $conflict->source_id === (int) $charge->id) {
            return $base;
        }

        return $base.'-R'.$charge->reservation_id;
    }

    /**
     * @param  array<int|string, string|null>  $roomNumberByReservationRoomId
     */
    public static function humanizeAccrualReference(?string $reference, array $roomNumberByReservationRoomId = []): string
    {
        if ($reference === null || $reference === '' || $reference === '—') {
            return $reference ?? '—';
        }

        if (preg_match('/^ACCRUAL-RR(\d+)-(.+)$/', $reference, $matches) !== 1) {
            return $reference;
        }

        $roomNumber = $roomNumberByReservationRoomId[(int) $matches[1]] ?? null;
        if ($roomNumber === null || $roomNumber === '') {
            return $reference;
        }

        return 'ACCRUAL-'.$roomNumber.'-'.$matches[2];
    }

    /**
     * Expand humanized accrual references (ACCRUAL-{room}-{date}) to legacy DB refs (ACCRUAL-RR{id}-{date}).
     *
     * @return array<int, string>
     */
    public static function accrualReferenceSearchTerms(string $search): array
    {
        $terms = [$search];

        if (preg_match('/^ACCRUAL-(\d+)-(.+)$/', $search, $matches) !== 1) {
            return $terms;
        }

        $roomNumber = $matches[1];
        $dateSuffix = $matches[2];

        $reservationRoomIds = ReservationRoom::query()
            ->whereHas('room', fn ($query) => $query->where('number', $roomNumber))
            ->pluck('id');

        foreach ($reservationRoomIds as $reservationRoomId) {
            $terms[] = 'ACCRUAL-RR'.$reservationRoomId.'-'.$dateSuffix;
        }

        return array_values(array_unique($terms));
    }

    private function resolveRoomNumberForCharge(ReservationDailyCharge $charge): ?string
    {
        if (! $charge->relationLoaded('room')) {
            $charge->load('room');
        }

        $number = $charge->room?->number;
        if ($number !== null && $number !== '') {
            return (string) $number;
        }

        if ($charge->room_id) {
            $number = Room::query()->whereKey($charge->room_id)->value('number');
            if ($number !== null && $number !== '') {
                return (string) $number;
            }
        }

        if (! $charge->relationLoaded('reservationRoom')) {
            $charge->load('reservationRoom.room');
        }

        $number = $charge->reservationRoom?->room?->number;
        if ($number !== null && $number !== '') {
            return (string) $number;
        }

        if ($charge->reservation_room_id) {
            $number = ReservationRoom::query()
                ->with('room')
                ->find($charge->reservation_room_id)
                ?->room
                ?->number;

            if ($number !== null && $number !== '') {
                return (string) $number;
            }
        }

        return null;
    }

    /**
     * A reservation booked "prepaid via platform" (the OTA already collected
     * the guest's money) never sees real cash hit the hotel — the platform
     * now owes the hotel this amount instead. Clears the guest's AR exactly
     * like a cash payment would, just crediting the OTA clearing account
     * instead of Cash. Idempotent: called once at booking-ingestion time.
     */
    /**
     * @param  float|null  $collectedAmount  the amount the platform actually
     *                                        collected from the guest (e.g.
     *                                        Channex booking payload's
     *                                        "amount") — this is what the
     *                                        platform will remit, which can
     *                                        legitimately differ from our own
     *                                        recomputed $reservation->total
     *                                        (different pricing-engine rate,
     *                                        OTA-side promotions, etc.).
     *                                        Falls back to $reservation->total
     *                                        only when the caller has no
     *                                        better figure.
     */
    public function postOtaPrepaidRecognition(Reservation $reservation, ?float $collectedAmount = null): ?JournalEntry
    {
        if ($reservation->ota_payment_type !== 'prepaid_platform') {
            return null;
        }

        $ref = 'OTA-PREPAID-'.$reservation->id;
        if (JournalEntry::where('reference', $ref)->exists()) {
            return null;
        }

        $amount = round((float) ($collectedAmount ?? $reservation->total), 2);
        if ($amount <= 0) {
            return null;
        }

        $ar = ChartOfAccount::where('code', '1100')->first();
        $otaClearing = ChartOfAccount::where('code', '1150')->first();
        if (! $ar || ! $otaClearing) {
            return null;
        }

        $entry = JournalEntry::create([
            'hotel_id' => $this->hotelResolver->forReservation($reservation),
            'entry_date' => now()->toDateString(),
            'reference' => $ref,
            'description' => 'OTA prepaid recognition — reservation #'.$reservation->id,
            'source_type' => 'ota_prepaid',
            'source_id' => $reservation->id,
        ]);

        JournalEntryLine::create([
            'journal_entry_id' => $entry->id,
            'account_id' => $otaClearing->id,
            'debit' => $amount,
            'credit' => 0,
            'reservation_id' => $reservation->id,
            'reservation_source_id' => $reservation->reservation_source_id,
            'memo' => 'Platform owes hotel (prepaid booking)',
        ]);
        JournalEntryLine::create([
            'journal_entry_id' => $entry->id,
            'account_id' => $ar->id,
            'debit' => 0,
            'credit' => $amount,
            'reservation_id' => $reservation->id,
            'reservation_source_id' => $reservation->reservation_source_id,
            'memo' => 'Guest AR cleared by platform prepayment',
        ]);

        return $entry->load('lines');
    }

    /**
     * Reverses postOtaPrepaidRecognition() for a reservation Channex has
     * since cancelled or superseded — the original entry is never edited or
     * deleted (standard practice for posted ledger entries), just offset
     * with the opposite entry so the platform's statement nets back to zero
     * for this booking.
     */
    public function reverseOtaPrepaidRecognition(Reservation $reservation): ?JournalEntry
    {
        $original = JournalEntry::where('reference', 'OTA-PREPAID-'.$reservation->id)->with('lines')->first();
        if ($original === null) {
            return null;
        }

        $reversalRef = 'OTA-PREPAID-REVERSAL-'.$reservation->id;
        if (JournalEntry::where('reference', $reversalRef)->exists()) {
            return null;
        }

        $otaClearingLine = $original->lines->firstWhere('debit', '>', 0);
        $arLine = $original->lines->firstWhere('credit', '>', 0);
        if (! $otaClearingLine || ! $arLine) {
            return null;
        }
        $amount = (float) $otaClearingLine->debit;

        $entry = JournalEntry::create([
            'hotel_id' => $this->hotelResolver->forReservation($reservation),
            'entry_date' => now()->toDateString(),
            'reference' => $reversalRef,
            'description' => 'OTA prepaid recognition reversed (cancelled/superseded) — reservation #'.$reservation->id,
            'source_type' => 'ota_prepaid_reversal',
            'source_id' => $reservation->id,
        ]);

        JournalEntryLine::create([
            'journal_entry_id' => $entry->id,
            'account_id' => $arLine->account_id,
            'debit' => $amount,
            'credit' => 0,
            'reservation_id' => $reservation->id,
            'reservation_source_id' => $reservation->reservation_source_id,
            'memo' => 'Reversal: guest AR restored (booking cancelled/superseded)',
        ]);
        JournalEntryLine::create([
            'journal_entry_id' => $entry->id,
            'account_id' => $otaClearingLine->account_id,
            'debit' => 0,
            'credit' => $amount,
            'reservation_id' => $reservation->id,
            'reservation_source_id' => $reservation->reservation_source_id,
            'memo' => 'Reversal: platform no longer owes hotel for this booking',
        ]);

        return $entry->load('lines');
    }

    /**
     * A "pay at hotel" OTA reservation collects cash from the guest exactly
     * like any other checkout (postPayment() handles that unchanged) — but
     * the hotel now owes the platform a commission on it. Posted once, when
     * this payment brings the reservation to fully paid (not prorated across
     * partial payments), into the same OTA clearing account used for prepaid
     * recognition, so one statement per platform nets both directions.
     */
    public function postOtaCommission(ReservationPay $payment): ?JournalEntry
    {
        if ((int) $payment->type !== ReservationPay::TYPE_PAYMENT) {
            return null;
        }

        $reservation = $payment->reservation ?? Reservation::find($payment->reservation_id);
        if (! $reservation || $reservation->ota_payment_type !== 'pay_at_hotel') {
            return null;
        }

        $reservation->refresh();
        if ($reservation->balanceDue() > 0.005) {
            return null;
        }

        $ref = 'OTA-COMMISSION-'.$reservation->id;
        if (JournalEntry::where('reference', $ref)->exists()) {
            return null;
        }

        $commissionAmount = round((float) ($reservation->external_commission_amount ?? 0), 2);
        if ($commissionAmount <= 0) {
            $percent = (float) (Reservation_source::find($reservation->reservation_source_id)?->default_commission_percent ?? 0);
            if ($percent <= 0) {
                return null;
            }
            $commissionAmount = round((float) $reservation->total * $percent / 100, 2);
        }
        if ($commissionAmount <= 0) {
            return null;
        }

        $commissionExpense = ChartOfAccount::where('code', '5030')->first();
        $otaClearing = ChartOfAccount::where('code', '1150')->first();
        if (! $commissionExpense || ! $otaClearing) {
            return null;
        }

        $entry = JournalEntry::create([
            'hotel_id' => $this->hotelResolver->forReservation($reservation),
            'entry_date' => now()->toDateString(),
            'reference' => $ref,
            'description' => 'OTA commission — reservation #'.$reservation->id,
            'source_type' => 'ota_commission',
            'source_id' => $reservation->id,
        ]);

        JournalEntryLine::create([
            'journal_entry_id' => $entry->id,
            'account_id' => $commissionExpense->id,
            'debit' => $commissionAmount,
            'credit' => 0,
            'reservation_id' => $reservation->id,
            'reservation_source_id' => $reservation->reservation_source_id,
            'memo' => 'OTA commission expense',
        ]);
        JournalEntryLine::create([
            'journal_entry_id' => $entry->id,
            'account_id' => $otaClearing->id,
            'debit' => 0,
            'credit' => $commissionAmount,
            'reservation_id' => $reservation->id,
            'reservation_source_id' => $reservation->reservation_source_id,
            'memo' => 'Hotel owes platform (commission)',
        ]);

        return $entry->load('lines');
    }

    /**
     * Running statement for one platform's account — positive balance means
     * the platform owes the hotel (net prepaid recognitions), negative means
     * the hotel owes the platform (net commissions).
     *
     * @return array{lines: array<int, array<string, mixed>>, total_debit: float, total_credit: float, balance: float}
     */
    public function statementForSource(int $reservationSourceId, ?Carbon $from = null, ?Carbon $to = null): array
    {
        $account = ChartOfAccount::where('code', '1150')->first();
        if (! $account) {
            return ['lines' => [], 'total_debit' => 0, 'total_credit' => 0, 'balance' => 0];
        }

        $query = JournalEntryLine::where('account_id', $account->id)
            ->where('reservation_source_id', $reservationSourceId)
            ->with('entry')
            ->orderBy('id');

        if ($from !== null && $to !== null) {
            $query->whereHas('entry', fn ($q) => $q->whereBetween('entry_date', [$from->toDateString(), $to->toDateString()]));
        }

        $lines = $query->get();
        $debit = round((float) $lines->sum('debit'), 2);
        $credit = round((float) $lines->sum('credit'), 2);

        return [
            'lines' => $lines->map(fn (JournalEntryLine $line) => [
                'entry_date' => $line->entry?->entry_date,
                'description' => $line->entry?->description,
                'reservation_id' => $line->reservation_id,
                'debit' => (float) $line->debit,
                'credit' => (float) $line->credit,
                'memo' => $line->memo,
            ])->values()->all(),
            'total_debit' => $debit,
            'total_credit' => $credit,
            'balance' => round($debit - $credit, 2),
        ];
    }

    /**
     * Deferred corporate billing — the company owes the hotel, not the
     * guest. Unlike postOtaPrepaidRecognition() (a pure journal-entry trick
     * with no ReservationPay row), this DELIBERATELY creates a real
     * ReservationPay(TYPE_BILLED_TO_COMPANY) row: Reservation::balanceDue()
     * only counts real payment rows, and the checkout guard in
     * ReservationController blocks checkout on any non-zero balance — a
     * journal-only entry would leave a company-billed reservation
     * permanently unable to check out. Never routed through
     * ReservationFinancialService::recordPayment() or postPayment() — both
     * unconditionally move real Cash (account 1010), which this is not.
     */
    public function postCompanyBillingRecognition(Reservation $reservation, int $userId): ?ReservationPay
    {
        if ($reservation->company_id === null) {
            return null;
        }

        $ref = 'COMPANY-BILLING-'.$reservation->id;
        if (JournalEntry::where('reference', $ref)->exists()) {
            return null;
        }

        $amount = round($reservation->balanceDue(), 2);
        if ($amount <= 0) {
            return null;
        }

        $ar = ChartOfAccount::where('code', '1100')->first();
        $corporateAr = ChartOfAccount::where('code', '1160')->first();
        if (! $ar || ! $corporateAr) {
            return null;
        }

        $payment = ReservationPay::create([
            'reservation_id' => $reservation->id,
            'pay' => $amount,
            'type' => ReservationPay::TYPE_BILLED_TO_COMPANY,
            'user_id' => $userId,
            'payment_source' => 'corporate_account',
        ]);

        $reservation->load('payments');
        $reservation->syncConfirmationIfFullyPaid();

        $entry = JournalEntry::create([
            'hotel_id' => $this->hotelResolver->forReservation($reservation),
            'entry_date' => now()->toDateString(),
            'reference' => $ref,
            'description' => 'Company billing recognition — reservation #'.$reservation->id,
            'source_type' => 'company_billing',
            'source_id' => $reservation->id,
        ]);

        JournalEntryLine::create([
            'journal_entry_id' => $entry->id,
            'account_id' => $corporateAr->id,
            'debit' => $amount,
            'credit' => 0,
            'reservation_id' => $reservation->id,
            'company_id' => $reservation->company_id,
            'memo' => 'Company owes hotel (deferred billing)',
        ]);
        JournalEntryLine::create([
            'journal_entry_id' => $entry->id,
            'account_id' => $ar->id,
            'debit' => 0,
            'credit' => $amount,
            'reservation_id' => $reservation->id,
            'company_id' => $reservation->company_id,
            'memo' => 'Guest AR cleared by corporate billing',
        ]);

        return $payment;
    }

    /**
     * Reverses postCompanyBillingRecognition() for a company-billed
     * reservation that's being cancelled. Deliberately NOT the normal
     * ReservationFinancialService refund flow (TYPE_REFUND always debits AR
     * and credits real Cash via postPayment() — there is no cash to give
     * back here). Posts the opposite journal entry only; the original
     * ReservationPay(TYPE_BILLED_TO_COMPANY) row is left in place as the
     * historical record of what was billed; the reservation being cancelled
     * already excludes it from any forward-looking balance elsewhere.
     */
    public function reverseCompanyBillingRecognition(Reservation $reservation): ?JournalEntry
    {
        $original = JournalEntry::where('reference', 'COMPANY-BILLING-'.$reservation->id)->with('lines')->first();
        if ($original === null) {
            return null;
        }

        $reversalRef = 'COMPANY-BILLING-REVERSAL-'.$reservation->id;
        if (JournalEntry::where('reference', $reversalRef)->exists()) {
            return null;
        }

        $corporateArLine = $original->lines->firstWhere('debit', '>', 0);
        $arLine = $original->lines->firstWhere('credit', '>', 0);
        if (! $corporateArLine || ! $arLine) {
            return null;
        }
        $amount = (float) $corporateArLine->debit;

        $entry = JournalEntry::create([
            'hotel_id' => $this->hotelResolver->forReservation($reservation),
            'entry_date' => now()->toDateString(),
            'reference' => $reversalRef,
            'description' => 'Company billing reversed (cancelled) — reservation #'.$reservation->id,
            'source_type' => 'company_billing_reversal',
            'source_id' => $reservation->id,
        ]);

        JournalEntryLine::create([
            'journal_entry_id' => $entry->id,
            'account_id' => $arLine->account_id,
            'debit' => $amount,
            'credit' => 0,
            'reservation_id' => $reservation->id,
            'company_id' => $reservation->company_id,
            'memo' => 'Reversal: guest AR restored (company-billed reservation cancelled)',
        ]);
        JournalEntryLine::create([
            'journal_entry_id' => $entry->id,
            'account_id' => $corporateArLine->account_id,
            'debit' => 0,
            'credit' => $amount,
            'reservation_id' => $reservation->id,
            'company_id' => $reservation->company_id,
            'memo' => 'Reversal: company no longer owes hotel for this booking',
        ]);

        return $entry->load('lines');
    }

    /**
     * Running statement for one company's corporate account — positive
     * balance means the company owes the hotel. Line-for-line copy of
     * statementForSource() (OTA), filtered by company_id instead of
     * reservation_source_id, against account 1160 instead of 1150.
     *
     * @return array{lines: array<int, array<string, mixed>>, total_debit: float, total_credit: float, balance: float}
     */
    public function statementForCompany(int $companyId, ?Carbon $from = null, ?Carbon $to = null): array
    {
        $account = ChartOfAccount::where('code', '1160')->first();
        if (! $account) {
            return ['lines' => [], 'total_debit' => 0, 'total_credit' => 0, 'balance' => 0];
        }

        $query = JournalEntryLine::where('account_id', $account->id)
            ->where('company_id', $companyId)
            ->with('entry')
            ->orderBy('id');

        if ($from !== null && $to !== null) {
            $query->whereHas('entry', fn ($q) => $q->whereBetween('entry_date', [$from->toDateString(), $to->toDateString()]));
        }

        $lines = $query->get();
        $debit = round((float) $lines->sum('debit'), 2);
        $credit = round((float) $lines->sum('credit'), 2);

        return [
            'lines' => $lines->map(fn (JournalEntryLine $line) => [
                'entry_date' => $line->entry?->entry_date,
                'description' => $line->entry?->description,
                'reservation_id' => $line->reservation_id,
                'debit' => (float) $line->debit,
                'credit' => (float) $line->credit,
                'memo' => $line->memo,
            ])->values()->all(),
            'total_debit' => $debit,
            'total_credit' => $credit,
            'balance' => round($debit - $credit, 2),
        ];
    }

    /**
     * Records an actual cash/bank receipt from a company settling its
     * outstanding corporate account balance. Rejects an amount exceeding the
     * current statement balance — same "never overpay past what's owed"
     * convention ReservationFinancialService::recordPayment() already
     * enforces against balanceDue().
     */
    public function postCompanySettlement(Company $company, float $amount, int $userId): JournalEntry
    {
        $amount = round($amount, 2);
        if ($amount <= 0) {
            throw new \InvalidArgumentException('Settlement amount must be greater than zero.');
        }

        $statement = $this->statementForCompany((int) $company->id);
        if ($amount > $statement['balance'] + 0.005) {
            throw new \InvalidArgumentException('Settlement amount exceeds the company\'s outstanding balance.');
        }

        $cash = ChartOfAccount::where('code', '1010')->first();
        $corporateAr = ChartOfAccount::where('code', '1160')->first();
        if (! $cash || ! $corporateAr) {
            throw new \RuntimeException('Required chart-of-accounts entries are missing.');
        }

        $entry = JournalEntry::create([
            'hotel_id' => $company->hotel_id,
            'entry_date' => now()->toDateString(),
            'reference' => 'COMPANY-SETTLEMENT-'.$company->id.'-'.now()->format('YmdHis'),
            'description' => 'Settlement received from company: '.$company->name,
            'user_id' => $userId,
            'source_type' => 'company_settlement',
            'source_id' => $company->id,
        ]);

        JournalEntryLine::create([
            'journal_entry_id' => $entry->id,
            'account_id' => $cash->id,
            'debit' => $amount,
            'credit' => 0,
            'company_id' => $company->id,
            'memo' => 'Cash received from company settlement',
        ]);
        JournalEntryLine::create([
            'journal_entry_id' => $entry->id,
            'account_id' => $corporateAr->id,
            'debit' => 0,
            'credit' => $amount,
            'company_id' => $company->id,
            'memo' => 'Corporate AR reduced by settlement',
        ]);

        return $entry->load('lines');
    }

    /**
     * Posts a POS charge (restaurant/cafe/laundry/minibar sale). Two branches:
     * paid immediately debits Cash and never touches the reservation (the
     * guest doesn't owe anything — they already paid); charged to room debits
     * guest AR and increments the reservation's subtotal/taxes/total in the
     * same call so the journal entry and the reservation balance can never
     * drift apart from the moment the charge is created.
     */
    public function postPosCharge(PosCharge $charge, ?int $userId): ?JournalEntry
    {
        $ref = 'POS-CHARGE-'.$charge->id;
        if (JournalEntry::where('reference', $ref)->exists()) {
            return null;
        }

        $category = PosCategory::with('account')->find($charge->pos_category_id);
        if (! $category || ! $category->account) {
            return null;
        }

        $amount = round((float) $charge->amount, 2);
        $tax = round((float) $charge->tax_amount, 2);
        $total = round($amount + $tax, 2);
        if ($total <= 0) {
            return null;
        }

        $vat = ChartOfAccount::where('code', '2150')->first();
        $isChargedToRoom = $charge->isChargedToRoom();
        $debitAccount = ChartOfAccount::where('code', $isChargedToRoom ? '1100' : '1010')->first();
        if (! $debitAccount || ! $vat) {
            return null;
        }

        $entry = JournalEntry::create([
            'hotel_id' => $charge->hotel_id,
            'entry_date' => $charge->charge_date,
            'reference' => $ref,
            'description' => 'POS charge — '.$category->name_en.($charge->reservation_id ? ' (reservation #'.$charge->reservation_id.')' : ' (walk-in)'),
            'user_id' => $userId,
            'source_type' => 'pos_charge',
            'source_id' => $charge->id,
        ]);

        JournalEntryLine::create([
            'journal_entry_id' => $entry->id,
            'account_id' => $debitAccount->id,
            'debit' => $total,
            'credit' => 0,
            'reservation_id' => $charge->reservation_id,
            'memo' => $isChargedToRoom ? 'Charged to guest folio' : 'Paid immediately',
        ]);
        JournalEntryLine::create([
            'journal_entry_id' => $entry->id,
            'account_id' => $category->account_id,
            'debit' => 0,
            'credit' => $amount,
            'reservation_id' => $charge->reservation_id,
            'memo' => 'POS revenue: '.$category->name_en,
        ]);
        if ($tax > 0) {
            JournalEntryLine::create([
                'journal_entry_id' => $entry->id,
                'account_id' => $vat->id,
                'debit' => 0,
                'credit' => $tax,
                'reservation_id' => $charge->reservation_id,
                'memo' => 'VAT on POS charge',
            ]);
        }

        if ($isChargedToRoom && $charge->reservation_id !== null) {
            $reservation = Reservation::find($charge->reservation_id);
            if ($reservation) {
                $reservation->subtotal = round((float) $reservation->subtotal + $amount, 2);
                $reservation->taxes = round((float) $reservation->taxes + $tax, 2);
                $reservation->total = round((float) $reservation->total + $total, 2);
                $reservation->save();
            }
        }

        return $entry->load('lines');
    }

    /**
     * Reverses postPosCharge() for a voided charge — mirrors each original
     * line (debit becomes credit and vice versa) rather than editing the
     * original entry. If it was charged to the room, the same amount is
     * removed from the reservation's subtotal/taxes/total; a paid-immediately
     * charge never touched the reservation, so nothing to undo there.
     */
    public function reverseVoidedPosCharge(PosCharge $charge): ?JournalEntry
    {
        $original = JournalEntry::where('reference', 'POS-CHARGE-'.$charge->id)->with('lines')->first();
        if ($original === null) {
            return null;
        }

        $reversalRef = 'POS-CHARGE-VOID-'.$charge->id;
        if (JournalEntry::where('reference', $reversalRef)->exists()) {
            return null;
        }

        $entry = JournalEntry::create([
            'hotel_id' => $charge->hotel_id,
            'entry_date' => now()->toDateString(),
            'reference' => $reversalRef,
            'description' => 'POS charge voided — reversal of POS-CHARGE-'.$charge->id,
            'source_type' => 'pos_charge_void',
            'source_id' => $charge->id,
        ]);

        foreach ($original->lines as $line) {
            JournalEntryLine::create([
                'journal_entry_id' => $entry->id,
                'account_id' => $line->account_id,
                'debit' => $line->credit,
                'credit' => $line->debit,
                'reservation_id' => $line->reservation_id,
                'memo' => 'Reversal: '.$line->memo,
            ]);
        }

        if ($charge->isChargedToRoom() && $charge->reservation_id !== null) {
            $reservation = Reservation::find($charge->reservation_id);
            if ($reservation) {
                $amount = round((float) $charge->amount, 2);
                $tax = round((float) $charge->tax_amount, 2);
                $reservation->subtotal = round((float) $reservation->subtotal - $amount, 2);
                $reservation->taxes = round((float) $reservation->taxes - $tax, 2);
                $reservation->total = round((float) $reservation->total - ($amount + $tax), 2);
                $reservation->save();
            }
        }

        return $entry->load('lines');
    }

    /**
     * Guards against the recalculation "reset points" (syncTotalsFromDailyCharges,
     * recalculateTotals) silently wiping out active room-charged POS charges —
     * both of those fully overwrite subtotal/taxes/total from the room price
     * alone with zero awareness of POS charges. Called from inside those two
     * methods, right after they finish assigning the fresh room-only totals.
     * Re-sums (never accumulates) from pos_charges each time, so it is safe no
     * matter how many times it runs.
     */
    public function reapplyActivePosChargesForReservation(Reservation $reservation): void
    {
        $sums = PosCharge::query()
            ->where('reservation_id', $reservation->id)
            ->where('payment_status', PosCharge::PAYMENT_STATUS_CHARGED_TO_ROOM)
            ->active()
            ->selectRaw('COALESCE(SUM(amount), 0) as amount_sum, COALESCE(SUM(tax_amount), 0) as tax_sum')
            ->first();

        $amount = round((float) ($sums->amount_sum ?? 0), 2);
        $tax = round((float) ($sums->tax_sum ?? 0), 2);
        if ($amount <= 0 && $tax <= 0) {
            return;
        }

        $reservation->subtotal = round((float) $reservation->subtotal + $amount, 2);
        $reservation->taxes = round((float) $reservation->taxes + $tax, 2);
        $reservation->total = round((float) $reservation->total + $amount + $tax, 2);
    }
}
