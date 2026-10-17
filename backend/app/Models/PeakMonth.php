<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PeakMonth extends Model
{
    use HasFactory;
    protected $table = 'peak_months';

    protected $fillable = ['month_name_ar', 'month_name_en', 'check'];
}
