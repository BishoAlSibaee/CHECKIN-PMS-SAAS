<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Tax extends Model
{
    protected $table = 'taxes';

    protected $fillable = ['type', 'value', 'name_ar', 'name_en', 'active'];

    public function reservationTaxes()
    {
        return $this->hasMany(ReservationTax::class, 'tax_id');
    }
}
