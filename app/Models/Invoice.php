<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Invoice extends Model
{
    public const TYPE_TAX_INVOICE = 'tax_invoice';

    public const TYPE_CREDIT_NOTE = 'credit_note';

    public const TYPE_DEBIT_NOTE = 'debit_note';

    public const STATUS_ISSUED = 'issued';

    public const STATUS_VOIDED = 'voided';

    protected $fillable = [
        'reservation_id',
        'related_invoice_id',
        'invoice_number',
        'type',
        'status',
        'subtotal',
        'tax_amount',
        'total',
        'currency',
        'line_items',
        'issued_by',
        'issued_at',
    ];

    protected $casts = [
        'line_items' => 'array',
        'subtotal' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'total' => 'decimal:2',
        'issued_at' => 'datetime',
    ];

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    public function relatedInvoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'related_invoice_id');
    }

    /** Credit/debit notes issued against this invoice. */
    public function adjustments(): HasMany
    {
        return $this->hasMany(Invoice::class, 'related_invoice_id');
    }

    public function zatcaInvoice(): HasOne
    {
        return $this->hasOne(ZatcaInvoice::class);
    }

    public function issuedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }
}
