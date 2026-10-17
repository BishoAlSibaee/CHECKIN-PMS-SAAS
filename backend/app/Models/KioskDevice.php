<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KioskDevice extends Model
{
    protected $fillable = [
        'name',
        'building_id',
        'user_id',
        'reservation_source_id',
        'default_stay_reason_id',
        'token_hash',
        'last_used_at',
        'active',
    ];

    protected $casts = [
        'last_used_at' => 'datetime',
        'active' => 'boolean',
    ];

    protected $hidden = [
        'token_hash',
    ];

    public function building(): BelongsTo
    {
        return $this->belongsTo(Building::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reservationSource(): BelongsTo
    {
        return $this->belongsTo(ReservationSource::class);
    }

    public function defaultStayReason(): BelongsTo
    {
        return $this->belongsTo(Stay_reason::class, 'default_stay_reason_id');
    }
}
