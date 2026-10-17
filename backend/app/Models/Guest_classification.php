<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Guest_classification extends Model
{
    use HasFactory;

    protected $connection = 'mysql';

    protected $table = 'guest_classifications';

    protected $fillable = ['name_ar', 'name_en', 'description', 'discount_id', 'active'];

    public function guest_classification_features(): HasMany
    {
        return $this->hasMany(Guest_classification_feature::class);
    }

    public function features()
    {
        return $this->belongsToMany(
            Guest_feature::class,
            'guest_classification_features',
            'guest_classification_id',
            'guest_feature_id'
        );
    }

    public function discount()
    {
        return $this->belongsTo(Discount::class, 'discount_id');
    }

    public function guest_features()
    {
        return $this->belongsToMany(Guest_feature::class, 'guest_classification_features');
    }
}
