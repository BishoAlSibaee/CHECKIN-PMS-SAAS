<?php

namespace App\Services\Accounting;

use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\Reservation;
use App\Models\ReservationDailyCharge;
use App\Models\ReservationPay;
use App\Models\ReservationRoom;
use App\Models\Room;

class AccountingPostingService
{
    private const TAX_RATE = 0.15;

    public function createManualEntry(array $payload, ?int $userId): JournalEntry
    {
        $entry = JournalEntry::create([
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

        $entry = JournalEntry::create([
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
}
