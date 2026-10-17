<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HotelTaxProfile extends Model
{
    protected $fillable = [
        'hotel_id',
        'vat_number',
        'cr_number',
        'org_name_ar',
        'org_name_en',
        'org_unit_name',
        'location',
        'industry',
    ];

    public function hotel(): BelongsTo
    {
        return $this->belongsTo(Hotel::class);
    }
}
