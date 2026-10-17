<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ReservationExtend extends Model
{
    use HasFactory;

    protected $table = 'reservation_extend';

    protected $fillable = [
        'reservation_id',
        'start_date',
        'end_date',
        'reason_id',
    ];

    public function reservation(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    public function stayReason(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Stay_reason::class, 'reason_id');
    }
}
