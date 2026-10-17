<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Job_title extends Model
{
    use HasFactory;

    protected $table = 'jobtitles';

    protected $fillable = ['name_ar', 'name_en', 'description', 'department_id'];

    function department(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'department_id');
    }

    function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
