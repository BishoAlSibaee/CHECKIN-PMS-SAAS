<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HotelInvoiceCounter extends Model
{
    protected $fillable = [
        'tax_hotel_id',
        'next_number',
    ];

    public function taxHotel(): BelongsTo
    {
        return $this->belongsTo(Hotel::class, 'tax_hotel_id');
    }
}
