<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GrmsHotelCredential extends Model
{
    public const DIRECTION_OUTBOUND = 'outbound';
    public const DIRECTION_INBOUND = 'inbound';

    protected $fillable = [
        'hotel_id',
        'direction',
        'key_id',
        'secret',
        'is_active',
        'rotated_at',
    ];

    protected $casts = [
        // Reversible, not a one-way hash — HMAC needs the real secret value
        // on both ends every time a signature is computed or verified.
        'secret' => 'encrypted',
        'is_active' => 'boolean',
        'rotated_at' => 'datetime',
    ];

    protected $hidden = [
        'secret',
    ];

    public function hotel(): BelongsTo
    {
        return $this->belongsTo(Hotel::class);
    }
}
