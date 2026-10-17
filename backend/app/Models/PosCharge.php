<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PosCharge extends Model
{
    use HasFactory;

    const PAYMENT_STATUS_PAID_IMMEDIATELY = 0;
    const PAYMENT_STATUS_CHARGED_TO_ROOM = 1;

    const STATUS_ACTIVE = 0;
    const STATUS_VOIDED = 1;

    protected $fillable = [
        'hotel_id',
        'reservation_id',
        'pos_category_id',
        'description',
        'invoice_reference',
        'quantity',
        'unit_price',
        'amount',
        'tax_amount',
        'charge_date',
        'payment_status',
        'status',
        'walkin_reference',
        'created_by',
        'voided_by',
        'voided_at',
    ];

    protected $casts = [
        'quantity' => 'decimal:2',
        'unit_price' => 'decimal:2',
        'amount' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'charge_date' => 'date',
        'voided_at' => 'datetime',
    ];

    public function hotel(): BelongsTo
    {
        return $this->belongsTo(Hotel::class);
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(PosCategory::class, 'pos_category_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function voider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    public function isActive(): bool
    {
        return (int) $this->status === self::STATUS_ACTIVE;
    }

    public function isChargedToRoom(): bool
    {
        return (int) $this->payment_status === self::PAYMENT_STATUS_CHARGED_TO_ROOM;
    }

    public function totalAmount(): float
    {
        return round((float) $this->amount + (float) $this->tax_amount, 2);
    }

    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }
}
