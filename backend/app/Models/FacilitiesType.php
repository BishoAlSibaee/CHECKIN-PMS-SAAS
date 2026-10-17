<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FacilitiesType extends Model
{
    use HasFactory;
    protected $table = 'facilities_type';
    public $timestamps = false;
    protected $fillable = [
        'name_ar',
        'name_en',
        'description',
    ];
}
