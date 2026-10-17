<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A general staff notification (new OTA booking, pending Airbnb request,
 * pricing changed, ...) — distinct from Laravel's own Notifiable/database
 * notifications (unused in this app), see the creating migration for why.
 */
class AppNotification extends Model
{
    protected $fillable = ['hotel_id', 'type', 'title_key', 'params', 'link', 'required_permission'];

    protected $casts = ['params' => 'array'];

    public function hotel(): BelongsTo
    {
        return $this->belongsTo(Hotel::class);
    }

    /**
     * Only notifications for the given hotel (or global, hotel_id === null)
     * that the given user actually holds the required permission for —
     * server-side filtering so a receptionist never even receives a
     * "pricing plan updated" item to begin with.
     */
    public function scopeVisibleTo(Builder $query, User $user, ?int $hotelId): Builder
    {
        $permissionNames = $user->getAllPermissions()->pluck('name')->all();

        return $query
            ->where(function (Builder $q) use ($hotelId) {
                $q->whereNull('hotel_id')->orWhere('hotel_id', $hotelId);
            })
            ->where(function (Builder $q) use ($permissionNames) {
                $q->whereNull('required_permission')->orWhereIn('required_permission', $permissionNames);
            });
    }
}
