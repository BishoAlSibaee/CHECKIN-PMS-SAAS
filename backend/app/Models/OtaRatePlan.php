<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class OtaRatePlan extends Model
{
    protected $fillable = [
        'hotel_id',
        'room_type_id',
        'name_ar',
        'name_en',
        'price_adjustment_percent',
        'is_refundable',
        'is_active',
    ];

    protected $casts = [
        'price_adjustment_percent' => 'float',
        'is_refundable' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function hotel(): BelongsTo
    {
        return $this->belongsTo(Hotel::class);
    }

    public function roomType(): BelongsTo
    {
        return $this->belongsTo(RoomType::class);
    }

    public function channexMapping(): HasOne
    {
        return $this->hasOne(ChannexRatePlanMapping::class);
    }
}
