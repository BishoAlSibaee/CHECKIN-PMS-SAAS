<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DoorUnlockHistory extends Model
{
    use HasFactory;
    protected $table = 'door_unlock_historys';

    public $timestamps = false;
    protected $fillable = ['room_id', 'room_number', 'user_id', 'user_name', 'created_at'];
}
