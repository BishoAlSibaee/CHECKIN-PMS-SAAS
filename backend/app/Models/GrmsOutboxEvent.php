<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GrmsOutboxEvent extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_SENT = 'sent';
    public const STATUS_ACCEPTED = 'accepted';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_STALE = 'stale';
    public const STATUS_REVIEW = 'review';

    /** Contract's exact retry schedule: 10s, 1m, 5m, 30m, 2h, 6h, then review. */
    public const RETRY_DELAYS_SECONDS = [10, 60, 300, 1800, 7200, 21600];

    protected $fillable = [
        'event_uuid',
        'hotel_id',
        'type',
        'entity_type',
        'entity_id',
        'version',
        'payload',
        'status',
        'attempts',
        'next_attempt_at',
        'last_error',
        'sync',
    ];

    protected $casts = [
        'payload' => 'array',
        'next_attempt_at' => 'datetime',
        'sync' => 'boolean',
    ];

    public function hotel(): BelongsTo
    {
        return $this->belongsTo(Hotel::class);
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, [self::STATUS_ACCEPTED, self::STATUS_REJECTED, self::STATUS_REVIEW], true);
    }
}
