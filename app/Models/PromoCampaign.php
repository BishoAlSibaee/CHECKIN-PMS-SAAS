<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A drafted promotional message campaign — nothing more. There is
 * deliberately no "send" action anywhere on this model or its controller;
 * see TaqnyatService's own docblock and the plan doc.
 */
class PromoCampaign extends Model
{
    use HasFactory;

    const AUDIENCE_MANUAL = 'manual';
    const AUDIENCE_CLASSIFICATION = 'classification';

    const STATUS_DRAFT = 'draft';

    protected $fillable = [
        'hotel_id',
        'channel',
        'message_body',
        'audience_type',
        'guest_classification_id',
        'recipient_count',
        'status',
        'created_by',
    ];

    public function hotel(): BelongsTo
    {
        return $this->belongsTo(Hotel::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(PromoCampaignRecipient::class);
    }
}
