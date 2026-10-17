<?php

namespace App\Models;

use Spatie\Permission\Models\Permission as SpatiePermission;

class Permission extends SpatiePermission
{
    protected $fillable = [
        'name',
        'name_ar',
        'guard_name',
        'category_id',
    ];

    public function category()
    {
        return $this->belongsTo(PermissionCategory::class, 'category_id');
    }
}
