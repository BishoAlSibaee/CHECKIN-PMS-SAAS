<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DynamicPricingRule extends Model
{
    use HasFactory;

    const METRIC_OCCUPANCY = 'occupancy';
    const METRIC_LEAD_TIME = 'lead_time';

    protected $fillable = [
        'room_type_id',
        'metric',
        'range_from',
        'range_to',
        'adjustment_percent',
    ];

    protected $casts = [
        'range_from' => 'integer',
        'range_to' => 'integer',
        'adjustment_percent' => 'float',
    ];

    public function roomType(): BelongsTo
    {
        return $this->belongsTo(RoomType::class, 'room_type_id');
    }

    public function matches(float $value): bool
    {
        if ($value < $this->range_from) {
            return false;
        }

        return $this->range_to === null || $value < $this->range_to;
    }
}
