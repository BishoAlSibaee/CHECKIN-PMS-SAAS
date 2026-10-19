<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The account/owner level above Hotel — one tenant can own several hotels
 * (branches), which share data (clients, etc.) as expected within one
 * business. A different tenant is a separate, unrelated customer and must
 * never see another tenant's data; see ResolveCurrentHotel for how a
 * request's current tenant is resolved from its current hotel.
 */
class Tenant extends Model
{
    protected $fillable = [
        'name_ar',
        'name_en',
        'is_active',
        'currency',
        'subscription_ends_at',
        'created_by_platform_admin_id',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'subscription_ends_at' => 'datetime',
    ];

    public function hotels(): HasMany
    {
        return $this->hasMany(Hotel::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(PlatformAdmin::class, 'created_by_platform_admin_id');
    }

    /** NULL subscription_ends_at = no expiry (platform's own tenant, or a permanent customer). */
    public function isSubscriptionActive(): bool
    {
        return $this->subscription_ends_at === null || $this->subscription_ends_at->isFuture();
    }

    /**
     * The single gate every login/request path should check — combines the
     * subscription clock with a platform operator's manual on/off switch.
     * Call sites that need to tell the two apart (for the error message
     * shown to the user) check is_active and isSubscriptionActive() directly
     * instead; this is for callers that only need a yes/no.
     */
    public function isAccessAllowed(): bool
    {
        return $this->is_active && $this->isSubscriptionActive();
    }
}
