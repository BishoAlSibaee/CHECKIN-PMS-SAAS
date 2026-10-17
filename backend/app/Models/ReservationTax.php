<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReservationTax extends Model
{
    protected $table = 'reservation_taxes';

    protected $fillable = ['reservation_id', 'tax_id', 'amount'];

    public function reservation()
    {
        return $this->belongsTo(Reservation::class, 'reservation_id');
    }

    public function tax()
    {
        return $this->belongsTo(Tax::class, 'tax_id');
    }
}
