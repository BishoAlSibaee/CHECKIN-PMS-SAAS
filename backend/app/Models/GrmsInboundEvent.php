<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Idempotency ledger only — see GrmsEventsController::processOne(). */
class GrmsInboundEvent extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'event_uuid',
        'type',
        'processed_at',
    ];

    protected $casts = [
        'processed_at' => 'datetime',
    ];
}
