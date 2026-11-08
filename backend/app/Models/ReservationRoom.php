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
        'logedin',
        'login_time',
        'logout_time',
    ];

    protected $casts = [
        'price' => 'double',
        'login_time' => 'datetime',
        'logout_time' => 'datetime',
    ];

    public const STATUS_ACTIVE = 0;

    public const STATUS_CANCELLED = 1;

    public const LOGEDIN_NOT_IN_HOUSE = 0;

    public const LOGEDIN_IN_HOUSE = 1;

    public static function isCancelled(int $status): bool
    {
        return $status === self::STATUS_CANCELLED;
    }

    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    public function scopeInHouse($query)
    {
        return $query->where('logedin', self::LOGEDIN_IN_HOUSE);
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

