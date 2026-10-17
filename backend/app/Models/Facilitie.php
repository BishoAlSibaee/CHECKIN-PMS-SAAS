<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Facilitie extends Model
{
    use HasFactory;
    public $timestamps = false;
    protected $fillable = [
        'name_ar',
        'name_en',
        'building_id',
        'floor_id',
        'facilities_types_id',
        'lock_data',
    ];

    public function building()
    {
        return $this->belongsTo(Building::class);
    }

    public function floor()
    {
        return $this->belongsTo(Floor::class);
    }

    public function facilitiesType()
    {
        return $this->belongsTo(FacilitiesType::class, 'facilities_types_id');
    }
}
