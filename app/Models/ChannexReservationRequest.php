<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChannexReservationRequest extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_RESOLVED = 'resolved';

    public const RESOLUTION_ACCEPT = 'accept';

    public const RESOLUTION_DECLINE = 'decline';

    public const RESOLVED_BY_STAFF = 'staff';

    public const RESOLVED_BY_AUTO_ACCEPT = 'auto_accept';

    public const RESOLVED_BY_AUTO_DECLINE = 'auto_decline';

    public const RESOLVED_BY_FALLBACK = 'fallback';

    /** Airbnb's own response deadline — Channex exposes no expiry field, so this is computed. */
    public const RESPONSE_WINDOW_HOURS = 24;

    protected $fillable = [
        'hotel_id',
        'channex_property_id',
        'live_feed_event_id',
        'message_thread_id',
        'channex_booking_id',
        'raw_payload',
        'received_at',
        'status',
        'resolution',
        'resolved_by',
        'resolved_by_user_id',
        'resolved_at',
        'last_reminder_sent_at',
        'error_message',
    ];

    protected $casts = [
        'raw_payload' => 'array',
        'received_at' => 'datetime',
        'resolved_at' => 'datetime',
        'last_reminder_sent_at' => 'datetime',
    ];

    public function hotel(): BelongsTo
    {
        return $this->belongsTo(Hotel::class);
    }

    public function resolvedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by_user_id');
    }

    public function deadline(): Carbon
    {
        return $this->received_at->copy()->addHours(self::RESPONSE_WINDOW_HOURS);
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }
}
