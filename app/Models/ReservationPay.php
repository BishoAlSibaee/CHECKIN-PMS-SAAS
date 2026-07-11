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
    ];

    const TYPE_PAYMENT = 0;
    const TYPE_REFUND = 1;

    public function reservation(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    public function user(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
