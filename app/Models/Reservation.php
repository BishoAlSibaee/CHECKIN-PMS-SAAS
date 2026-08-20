<?php

namespace App\Models;

use App\Support\ReservationRelatedCleanup;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Reservation extends Model
{
    use HasFactory;

    protected static function booted(): void
    {
        static::deleting(function (Reservation $reservation): void {
            ReservationRelatedCleanup::purgeRelated([(int) $reservation->id]);
        });
    }

    /** Guest physically in room (used by RoomOccupancyService and UI). */
    public const LOGEDIN_IN_HOUSE = 1;

    /** Guest not checked in yet (or checked out). */
    public const LOGEDIN_NOT_IN_HOUSE = 0;

    /** Draft / unconfirmed. */
    public const STATUS_UNCONFIRMED = 0;

    /** Confirmed active reservation. */
    public const STATUS_CONFIRMED = 1;

    /** Awaiting payment — still holds calendar visibility but excluded from accrual. */
    public const STATUS_PENDING_PAYMENT = 2;

    /** Cancelled or voided. */
    public const STATUS_CANCELLED = 3;

    public static function isCancelled(int $status): bool
    {
        return $status === self::STATUS_CANCELLED;
    }

    /** Statuses excluded from inventory overlap checks (pending + cancelled). */
    public static function nonBlockingInventoryStatuses(): array
    {
        return [self::STATUS_PENDING_PAYMENT, self::STATUS_CANCELLED];
    }

    /** Statuses included in cash / payment reports. */
    public static function cashReportStatuses(): array
    {
        return [self::STATUS_CONFIRMED, self::STATUS_PENDING_PAYMENT];
    }

    /**
     * Statuses whose actual cash movements (payments/refunds) count in cash reports.
     * Cash is cash: a cancelled reservation can still have a real payment and refund,
     * so cash-box / payments-refunds must include cancelled to reconcile with the ledger.
     */
    public static function cashMovementStatuses(): array
    {
        return [
            self::STATUS_UNCONFIRMED,
            self::STATUS_CONFIRMED,
            self::STATUS_PENDING_PAYMENT,
            self::STATUS_CANCELLED,
        ];
    }

    /** Statuses shown on operational reports (excludes pending + cancelled). */
    public static function operationalReportStatuses(): array
    {
        return [self::STATUS_UNCONFIRMED, self::STATUS_CONFIRMED];
    }

    /** Exclude cancelled reservations (matches reservations list / table). */
    public function scopeExcludingCancelled($query)
    {
        return $query->where('reservation_status', '!=', self::STATUS_CANCELLED);
    }

    /** Non-cancelled reservations overlapping a calendar day (matches list/calendar filter). */
    public function scopeOverlappingDate($query, string $date)
    {
        return $query->where('start_date', '<=', $date)
            ->where('expire_date', '>=', $date);
    }

    public static function countActiveOnDate(string $date): int
    {
        return (int) static::query()->excludingCancelled()->overlappingDate($date)->count();
    }

    /** Reservations with outstanding balance (total − net payments > 0). */
    public function scopeWithPositiveBalance($query)
    {
        $paymentType = ReservationPay::TYPE_PAYMENT;
        $refundType = ReservationPay::TYPE_REFUND;

        return $query->whereRaw(
            '(reservations.total - COALESCE((
                SELECT SUM(CASE WHEN rp.type = ? THEN rp.pay WHEN rp.type = ? THEN -rp.pay ELSE 0 END)
                FROM reservation_pay rp
                WHERE rp.reservation_id = reservations.id
            ), 0)) > ?',
            [$paymentType, $refundType, 0.005]
        );
    }

    /** Outstanding guest balances for AR aging / collections (not cancelled). */
    public function scopeOutstandingForAging($query)
    {
        return $query->excludingCancelled()
            ->whereIn('reservation_status', self::cashReportStatuses())
            ->withPositiveBalance();
    }

    /**
     * Confirmed stays, plus pending-payment stays that are fully paid (data safety net).
     */
    public function scopeAccrualEligible($query)
    {
        $paymentType = ReservationPay::TYPE_PAYMENT;
        $refundType = ReservationPay::TYPE_REFUND;

        return $query->where(function ($q) use ($paymentType, $refundType) {
            $q->where('reservations.reservation_status', self::STATUS_CONFIRMED)
                ->orWhere(function ($pending) use ($paymentType, $refundType) {
                    $pending->where('reservations.reservation_status', self::STATUS_PENDING_PAYMENT)
                        ->whereRaw(
                            '(reservations.total - COALESCE((
                                SELECT SUM(CASE WHEN rp.type = ? THEN rp.pay WHEN rp.type = ? THEN -rp.pay ELSE 0 END)
                                FROM reservation_pay rp
                                WHERE rp.reservation_id = reservations.id
                            ), 0)) <= ?',
                            [$paymentType, $refundType, 0.005]
                        );
                });
        });
    }

    public function guestDisplayName(): string
    {
        if (! $this->relationLoaded('client')) {
            $this->load('client');
        }

        $name = trim(($this->client->first_name ?? '').' '.($this->client->last_name ?? ''));

        return $name !== '' ? $name : '—';
    }

    public function syncConfirmationIfFullyPaid(): bool
    {
        $this->loadMissing('payments');

        if ((int) $this->reservation_status !== self::STATUS_PENDING_PAYMENT) {
            return false;
        }

        if ($this->balanceDue() > 0.005) {
            return false;
        }

        $this->reservation_status = self::STATUS_CONFIRMED;
        $this->save();

        return true;
    }

    protected $fillable = [
        'client_id',
        'start_date',
        'nights',
        'expire_date',
        'reservation_type',
        'reservation_status',
        'stay_reason_id',
        'reservation_source_id',
        'rent_type',
        'price_calculation_mode',
        'base_price',
        'discount',
        'classification_discount',
        'guest_classification_id',
        'classification_discount_id',
        'extras',
        'penalties',
        'subtotal',
        'taxes',
        'total',
        'logedin',
        'login_time',
        'user_id',
    ];

    public $timestamps = true;

    /**
     * Get the rooms associated with this reservation (from reservation_rooms table)
     */
    public function reservationRooms(): HasMany
    {
        return $this->hasMany(ReservationRoom::class);
    }

    /**
     * Get the first room of this reservation (for backward compatibility)
     */
    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function stay_reason(): BelongsTo
    {
        return $this->belongsTo(Stay_reason::class);
    }

    public function reservation_source(): BelongsTo
    {
        return $this->belongsTo(Reservation_source::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(ReservationPay::class);
    }

    public function extensions(): HasMany
    {
        return $this->hasMany(ReservationExtend::class);
    }

    public function paidNetAmountAsOf(\Carbon\Carbon $asOf): float
    {
        if (! $this->relationLoaded('payments')) {
            $this->load('payments');
        }

        $cutoff = $asOf->copy()->endOfDay();

        $paid = (float) $this->payments
            ->where('type', ReservationPay::TYPE_PAYMENT)
            ->filter(fn (ReservationPay $pay) => $pay->created_at !== null && $pay->created_at->lte($cutoff))
            ->sum('pay');
        $refunded = (float) $this->payments
            ->where('type', ReservationPay::TYPE_REFUND)
            ->filter(fn (ReservationPay $pay) => $pay->created_at !== null && $pay->created_at->lte($cutoff))
            ->sum('pay');

        return round($paid - $refunded, 2);
    }

    public function balanceDueAsOf(\Carbon\Carbon $asOf, ?float $total = null): float
    {
        $totalAmount = $total ?? (float) $this->total;
        if ($totalAmount <= 0 && (float) $this->subtotal > 0) {
            $totalAmount = round((float) $this->subtotal * 1.15, 2);
        }

        return round(max(0, $totalAmount - $this->paidNetAmountAsOf($asOf)), 2);
    }

    public function paidNetAmount(): float
    {
        if (! $this->relationLoaded('payments')) {
            $this->load('payments');
        }

        $paid = (float) $this->payments
            ->where('type', ReservationPay::TYPE_PAYMENT)
            ->sum('pay');
        $refunded = (float) $this->payments
            ->where('type', ReservationPay::TYPE_REFUND)
            ->sum('pay');

        return round($paid - $refunded, 2);
    }

    public function balanceDue(?float $total = null): float
    {
        $totalAmount = $total ?? (float) $this->total;

        return round(max(0, $totalAmount - $this->paidNetAmount()), 2);
    }

    public function guestHasCheckedOut(): bool
    {
        if ((int) $this->logedin !== self::LOGEDIN_NOT_IN_HOUSE || empty($this->login_time)) {
            return false;
        }

        if (ReservationChangeLog::query()
            ->where('reservation_id', $this->id)
            ->where('action', 'checked_in')
            ->exists()) {
            return true;
        }

        if ($this->expire_date === null) {
            return false;
        }

        return \Carbon\Carbon::parse($this->expire_date)->startOfDay()->lt(\Carbon\Carbon::today()->startOfDay());
    }

    public function totalDiscountAmount(): float
    {
        return round((float) $this->classification_discount + (float) $this->discount, 2);
    }

    public function guestClassification(): BelongsTo
    {
        return $this->belongsTo(Guest_classification::class, 'guest_classification_id');
    }

    public function classificationDiscountRule(): BelongsTo
    {
        return $this->belongsTo(Discount::class, 'classification_discount_id');
    }
}
