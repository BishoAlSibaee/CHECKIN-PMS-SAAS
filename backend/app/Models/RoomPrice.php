<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RoomPrice extends Model
{
    use HasFactory;

    protected $table = 'room_prices';

    protected $fillable = [
        'reservation_room_id',
        'pricing_plan_daily',
        'pricing_plan_monthly',
        'max_price',
        'min_price',
        'max_month',
        'min_month',
        'start_plan',
        'end_plan',
    ];

    protected $casts = [
        'pricing_plan_daily' => 'decimal:2',
        'pricing_plan_monthly' => 'decimal:2',
        'max_price' => 'decimal:2',
        'min_price' => 'decimal:2',
        'max_month' => 'decimal:2',
        'min_month' => 'decimal:2',
        'start_plan' => 'date',
        'end_plan' => 'date',
    ];

    public function reservationRoom()
    {
        return $this->belongsTo(ReservationRoom::class);
    }

    public function maxDays()
    {
        return $this->hasMany(RoomPriceMaxDay::class, 'room_price_id');
    }

    public function maxMonths()
    {
        return $this->hasMany(RoomPriceMaxMonth::class, 'room_price_id');
    }
}

