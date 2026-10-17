<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Pricingplan extends Model
{
    use HasFactory;
    protected $table = 'pricing_plans';
    protected $fillable = ['NameAr', 'NameEn', 'StartDate', 'EndDate', 'ActiveType'];
}
