<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PermissionCategory extends Model
{
    use HasFactory;

    protected $fillable = ['name_en', 'name_ar'];

    public function permissions()
    {
        return $this->hasMany(config('permission.models.permission'), 'category_id');
    }
}
