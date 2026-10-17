<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AccountingPeriod extends Model
{
    protected $fillable = ['hotel_id', 'year', 'month', 'status', 'closed_at', 'closed_by'];
}
