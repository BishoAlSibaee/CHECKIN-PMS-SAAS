<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReservationRoom extends Model
{
    use HasFactory;

    protected $fillable = [
        'reservation_id',
        'room_id',
        'suite_id',
        'price',
        'status',
    ];

    protected $casts = [
        'price' => 'double',
    ];

    public const STATUS_ACTIVE = 0;

    public const STATUS_CANCELLED = 1;

    public static function isCancelled(int $status): bool
    {
        return $status === self::STATUS_CANCELLED;
    }

    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    /**
     * Get the reservation that owns this room reservation
     */
    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    /**
     * Get the room associated with this reservation
     */
    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    /**
     * Get the suite associated with this reservation (if any)
     */
    public function suite(): BelongsTo
    {
        return $this->belongsTo(Suite::class);
    }

    public function roomPrices()
    {
        return $this->hasMany(RoomPrice::class, 'reservation_room_id');
    }
}

