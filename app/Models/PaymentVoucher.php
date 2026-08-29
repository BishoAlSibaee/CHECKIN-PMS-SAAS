<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentVoucher extends Model
{
    public const TYPE_RECEIPT = 'receipt';

    public const TYPE_DISBURSEMENT = 'disbursement';

    protected $fillable = [
        'reservation_pay_id',
        'voucher_number',
        'type',
        'amount',
        'method',
        'issued_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'issued_at' => 'datetime',
    ];

    public function reservationPay(): BelongsTo
    {
        return $this->belongsTo(ReservationPay::class);
    }
}
