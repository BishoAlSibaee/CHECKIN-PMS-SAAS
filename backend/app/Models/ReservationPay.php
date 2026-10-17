<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ReservationPay extends Model
{
    use HasFactory;

    protected $table = 'reservation_pay';

protected $fillable = [
        'reservation_id',
        'pay',
        'type',
        'note',
        'user_id',
        'terminal_reference',
        'payment_source',
        'card_last4',
    ];

    const TYPE_PAYMENT = 0;
    const TYPE_REFUND = 1;
    /** Non-cash: the guest owes nothing, a corporate account is billed instead (see AccountingPostingService::postCompanyBillingRecognition). */
    const TYPE_BILLED_TO_COMPANY = 2;

    public function reservation(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    public function user(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function voucher(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(PaymentVoucher::class);
    }
}
