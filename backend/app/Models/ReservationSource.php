<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ReservationSource extends Model
{
    use HasFactory;
    public $table = 'reservation_sources';
    protected $fillable = ['name_ar', 'name_en', 'description', 'is_ota_channel', 'channex_channel_code', 'default_commission_percent'];
}
