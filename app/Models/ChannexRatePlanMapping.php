<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChannexRatePlanMapping extends Model
{
    protected $fillable = ['ota_rate_plan_id', 'channex_rate_plan_id', 'last_synced_at'];

    protected $casts = ['last_synced_at' => 'datetime'];

    public function otaRatePlan(): BelongsTo
    {
        return $this->belongsTo(OtaRatePlan::class);
    }
}
