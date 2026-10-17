<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChannexRoomTypeMapping extends Model
{
    protected $fillable = ['hotel_id', 'room_type_id', 'channex_room_type_id', 'last_synced_at'];

    protected $casts = ['last_synced_at' => 'datetime'];

    public function hotel(): BelongsTo
    {
        return $this->belongsTo(Hotel::class);
    }

    public function roomType(): BelongsTo
    {
        return $this->belongsTo(RoomType::class);
    }
}
