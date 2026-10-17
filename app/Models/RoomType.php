<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RoomType extends Model
{
    use HasFactory;
    protected $table = 'room_types';

    protected $fillable = ['name_ar', 'name_en', 'description', 'Max_daily_price', 'Min_daily_price', 'Max_monthly_price', 'Min_monthly_price', 'active_type', 'dynamic_pricing_enabled'];
    protected $casts = ['dynamic_pricing_enabled' => 'boolean'];
    public $timestamps = true;
    function rooms(): HasMany
    {
        return $this->hasMany(Room::class);
    }

    function dynamicPricingRules(): HasMany
    {
        return $this->hasMany(DynamicPricingRule::class, 'room_type_id');
    }
}
