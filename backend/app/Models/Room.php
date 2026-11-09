<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Model;

class Room extends Model
{
    use HasFactory;
    public $timestamps = true;

    protected $casts = [
        'grms_occupancy_changed_at' => 'datetime',
    ];

    /**
     * GRMS v1 contract staleness check for room.blocked/unblocked — same
     * pattern as Reservation::version.
     */
    protected static function booted(): void
    {
        static::saving(function (Room $room): void {
            if ($room->exists && $room->isDirty()) {
                $room->version = ((int) ($room->getOriginal('version') ?? 1)) + 1;
            }
        });
    }

    function suite(): BelongsTo
    {
        return $this->belongsTo(Suite::class);
    }

    function floor(): BelongsTo
    {
        return $this->belongsTo(Floor::class);
    }

    function roomFeatures(): HasMany
    {
        return $this->hasMany(Room_feature::class);
    }

    function building(): BelongsTo
    {
        return $this->belongsTo(Building::class);
    }

    function roomType(): BelongsTo
    {
        return $this->belongsTo(RoomType::class);
    }

    function isHasReservation()
    {
        return ReservationRoom::where('room_id', $this->id)->exists();
    }

    /**
     * Get the reservations for this room (from reservation_rooms table)
     */
    function reservationRooms()
    {
        return $this->hasMany(ReservationRoom::class);
    }
}

