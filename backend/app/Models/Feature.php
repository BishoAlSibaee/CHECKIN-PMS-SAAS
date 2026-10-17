<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Feature extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'name_ar',
        'name_en',
        'description',
    ];

    public function room_features(): HasMany
    {
        return $this->hasMany(Room_feature::class);
    }
}
