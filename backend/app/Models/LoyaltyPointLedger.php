<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LoyaltyPointLedger extends Model
{
    protected $fillable = [
        'client_id',
        'reservation_id',
        'reservation_pay_id',
        'points',
        'reason',
        'user_id',
    ];

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    public function reservationPay(): BelongsTo
    {
        return $this->belongsTo(ReservationPay::class);
    }

    /** Client lives on the "mysql2" connection — no DB-level FK, relation only. */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
}
