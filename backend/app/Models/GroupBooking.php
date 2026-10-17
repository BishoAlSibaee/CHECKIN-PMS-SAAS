<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GroupBooking extends Model
{
    use HasFactory;

    protected $fillable = [
        'hotel_id',
        'name',
        'company_name',
        'contact_name',
        'contact_mobile',
        'contact_email',
        'notes',
        'group_discount_percent',
        'status',
        'created_by',
    ];

    protected $casts = [
        'group_discount_percent' => 'float',
    ];

    public const STATUS_DRAFT = 0;

    public const STATUS_ACTIVE = 1;

    public const STATUS_CANCELLED = 2;

    public static function isCancelled(int $status): bool
    {
        return $status === self::STATUS_CANCELLED;
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class, 'group_booking_id');
    }

    public function hotel(): BelongsTo
    {
        return $this->belongsTo(Hotel::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
