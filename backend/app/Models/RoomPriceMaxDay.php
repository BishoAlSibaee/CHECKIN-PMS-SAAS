<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RoomPriceMaxDay extends Model
{
    use HasFactory;

    protected $table = 'room_price_max_days';

    protected $fillable = [
        'room_price_id',
        'day',
    ];


    public function roomPrice()
    {
        return $this->belongsTo(RoomPrice::class);
    }
}

