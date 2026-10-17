<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Penaltie extends Model
{
    use HasFactory;
    protected $table = 'penalties';
    protected $fillable = ['type', 'value', 'name_ar', 'name_en', 'description'];

    protected $casts = [
        'type' => 'integer',
        'value' => 'double',
    ];
}
