<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class ReportExport extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_READY = 'ready';
    public const STATUS_FAILED = 'failed';

    public const FORMAT_EXCEL = 'excel';
    public const FORMAT_PDF = 'pdf';

    protected $fillable = [
        'user_id',
        'slug',
        'file_format',
        'recipient_email',
        'start_date',
        'end_date',
        'compare_start_date',
        'compare_end_date',
        'status',
        'file_path',
        'download_token',
        'row_count',
        'error_message',
        'processed_at',
        'expires_at',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'compare_start_date' => 'date',
        'compare_end_date' => 'date',
        'processed_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public static function generateToken(): string
    {
        return Str::random(48);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function downloadUrl(): string
    {
        return rtrim(config('app.url'), '/')
            . '/api/users/reports/exports/' . $this->id
            . '/download?token=' . urlencode($this->download_token);
    }

    public function fileExtension(): string
    {
        return $this->file_format === self::FORMAT_PDF ? 'pdf' : 'xlsx';
    }

    public function mimeType(): string
    {
        return $this->file_format === self::FORMAT_PDF
            ? 'application/pdf'
            : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
    }

    public function formatLabel(): string
    {
        return $this->file_format === self::FORMAT_PDF ? 'PDF' : 'Excel';
    }
}
