<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RoomPriceMaxMonth extends Model
{
    use HasFactory;

    protected $table = 'room_price_max_months';

    protected $fillable = [
        'room_price_id',
        'month',
];

    protected $casts = [
        'price' => 'decimal:2',
    ];

    public function roomPrice()
    {
        return $this->belongsTo(RoomPrice::class);
    }
}

