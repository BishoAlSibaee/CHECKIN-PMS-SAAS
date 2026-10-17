<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PeakDay extends Model
{
    protected $table = 'peak_days';

    protected $fillable = ['day_name_ar', 'day_name_en', 'check'];
}
