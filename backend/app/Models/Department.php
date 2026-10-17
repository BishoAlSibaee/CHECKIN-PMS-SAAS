<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Department extends Model
{
    use HasFactory;
    public $table = 'departments';
    protected $fillable = ['name_ar', 'name_en', 'description'];

    function jobtitles(): HasMany
    {
        return $this->hasMany(Job_title::class);
    }
}
