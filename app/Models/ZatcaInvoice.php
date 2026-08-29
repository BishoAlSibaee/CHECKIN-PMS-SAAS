<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ZatcaInvoice extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_REPORTED = 'reported';

    public const STATUS_FAILED = 'failed';

    public const STATUS_SKIPPED = 'skipped';

    protected $fillable = [
        'reservation_id',
        'invoice_number',
        'uuid',
        'invoice_hash',
        'qr_code',
        'icv',
        'status',
        'reporting_status',
        'http_status',
        'signed_xml_path',
        'response_payload',
        'error_message',
        'reported_at',
    ];

    protected $casts = [
        'response_payload' => 'array',
        'reported_at' => 'datetime',
        'icv' => 'integer',
        'http_status' => 'integer',
    ];

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }
}
