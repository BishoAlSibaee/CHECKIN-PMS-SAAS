<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Model;

class Room_feature extends Model
{
    use HasFactory;

    protected $table = 'rooms_features';
    protected $fillable = ['room_id', 'feature_id', 'number'];
    public $timestamps = false;

function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    function feature(): BelongsTo
    {
        return $this->belongsTo(Feature::class);
    }
}
