<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReservationDailyCharge extends Model
{
    protected $fillable = [
        'reservation_id',
        'reservation_room_id',
        'room_id',
        'charge_date',
        'base_amount',
        'is_peak_day',
        'is_in_plan',
        'price_source',
        'rent_type',
    ];

    protected $casts = [
        'charge_date' => 'date',
        'base_amount' => 'decimal:2',
        'is_peak_day' => 'boolean',
        'is_in_plan' => 'boolean',
    ];

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    public function reservationRoom(): BelongsTo
    {
        return $this->belongsTo(ReservationRoom::class);
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }
}
