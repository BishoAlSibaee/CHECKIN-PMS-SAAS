<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RoomtypePricingplan extends Model
{
    use HasFactory;
    protected $table = 'roomtype_pricingplan';
    protected $fillable = ['roomtype_id', 'pricingplan_id', 'DailyPrice', 'MonthlyPrice'];

    public function pricingplan()
    {
        return $this->belongsTo(Pricingplan::class, 'pricingplan_id');
    }

    public function roomType()
    {
        return $this->belongsTo(RoomType::class, 'roomtype_id');
    }
}
