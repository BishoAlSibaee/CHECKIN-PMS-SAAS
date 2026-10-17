<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChannexProperty extends Model
{
    protected $fillable = ['hotel_id', 'channex_property_id', 'last_synced_at'];

    protected $casts = ['last_synced_at' => 'datetime'];

    public function hotel(): BelongsTo
    {
        return $this->belongsTo(Hotel::class);
    }
}
