<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A branch/property — normally a separate legal entity with its own VAT
 * registration and books, unless it opts to share another branch's tax
 * registration (shares_tax_with_hotel_id). Named "Hotel" rather than
 * "Property" to avoid colliding with the existing "property" domain
 * (routes/users/property.php), which already means the physical building/
 * floor/room hierarchy.
 */
class Hotel extends Model
{
    protected $fillable = [
        'tenant_id',
        'name_ar',
        'name_en',
        'is_active',
        'shares_tax_with_hotel_id',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function buildings(): HasMany
    {
        return $this->hasMany(Building::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'hotel_user');
    }

    /** The other branch this hotel borrows its tax registration from, if any. */
    public function sharesTaxWith(): BelongsTo
    {
        return $this->belongsTo(self::class, 'shares_tax_with_hotel_id');
    }

    /** This hotel's own VAT/CR profile — only present when it is a tax owner. */
    public function taxProfile(): HasOne
    {
        return $this->hasOne(HotelTaxProfile::class);
    }
}
