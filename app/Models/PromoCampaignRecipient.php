<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PromoCampaignRecipient extends Model
{
    use HasFactory;

    protected $fillable = [
        'promo_campaign_id',
        'client_id',
        'phone_snapshot',
    ];

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(PromoCampaign::class, 'promo_campaign_id');
    }
}
