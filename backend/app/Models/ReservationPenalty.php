<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;


class ReservationPenalty extends Model
{
    protected $table = 'reservation_penalties';

    protected $fillable = ['reservation_id', 'penalty_id', 'amount'];

    public $timestamps = true;

    public function reservation()
    {
        return $this->belongsTo(Reservation::class, 'reservation_id');
    }

    public function penalty()
    {
        return $this->belongsTo(Penaltie::class, 'penalty_id');
    }
}
