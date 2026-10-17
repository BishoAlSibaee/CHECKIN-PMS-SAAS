<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HotelAirbnbRequestSetting extends Model
{
    public const MODE_AUTO_ACCEPT = 'auto_accept';

    public const MODE_MANUAL_REVIEW = 'manual_review';

    public const MODE_AUTO_DECLINE = 'auto_decline';

    public const FALLBACK_ACCEPT = 'accept';

    public const FALLBACK_DECLINE = 'decline';

    /** Channex's fixed decline-reason enum for /live_feed/{id}/resolve. */
    public const DECLINE_REASONS = [
        'dates_not_available',
        'not_a_good_fit',
        'waiting_for_better_reservation',
        'not_comfortable',
    ];

    protected $fillable = [
        'hotel_id',
        'mode',
        'reminder_interval_minutes',
        'fallback_action',
        'decline_reason',
        'updated_by',
    ];

    /**
     * Mirrors the migration's column defaults. firstOrCreate() builds its
     * in-memory instance from the create array alone — it does NOT reload
     * the row after insert — so without these, a freshly-created settings
     * row would read back as null for mode/reminder/fallback until the next
     * fetch, silently breaking the reminder and fallback-expiry commands.
     */
    protected $attributes = [
        'mode' => self::MODE_MANUAL_REVIEW,
        'reminder_interval_minutes' => 60,
        'fallback_action' => self::FALLBACK_DECLINE,
        'decline_reason' => 'not_comfortable',
    ];

    public function hotel(): BelongsTo
    {
        return $this->belongsTo(Hotel::class);
    }

    public static function forHotel(int $hotelId): self
    {
        return static::firstOrCreate(['hotel_id' => $hotelId]);
    }
}
