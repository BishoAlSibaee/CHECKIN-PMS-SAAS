<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NightAuditRun extends Model
{
    protected $fillable = [
        'hotel_id',
        'audit_date',
        'metrics',
        'exceptions',
        'exceptions_count',
        'generated_at',
    ];

    protected $casts = [
        'audit_date' => 'date',
        'metrics' => 'array',
        'exceptions' => 'array',
        'generated_at' => 'datetime',
    ];

    public function hotel(): BelongsTo
    {
        return $this->belongsTo(Hotel::class);
    }
}
