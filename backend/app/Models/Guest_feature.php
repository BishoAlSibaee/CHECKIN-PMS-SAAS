<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Guest_feature extends Model
{
    use HasFactory;

    protected $table = 'guests_features';
    protected $fillable = ['name_ar', 'name_en', 'feature_description'];


    function guest_classification_features(): HasMany
    {
        return $this->hasMany(Guest_classification_feature::class, 'guest_feature_id');
    }

    function guest_classifications()
    {
        return $this->belongsToMany(
            Guest_classification::class,
            'guest_classification_features',
            'guest_feature_id',
            'guest_classification_id'
        );
    }
        public function guest_feature()
{
    return $this->belongsToMany(Guest_feature::class, 'guest_classification_features');
}
}
