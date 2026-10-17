<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Passport\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, HasRoles;

    protected $guard_name = 'api';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'job_number',
        'jobtitle_id',
        'department_id',
        'discount_id',
        'mobile',
        'email',
        'password',
        'active',
        'notifications_seen_at',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
        'notifications_seen_at' => 'datetime',
    ];


    function jobtitle() : BelongsTo
    {
        return $this->belongsTo(Job_title::class);
    }

    function department() : BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    function discount() : BelongsTo
    {
        return $this->belongsTo(Discount::class);
    }

    /** Hotels (branches) this user can access — see hotel_user pivot. */
    function hotels(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(Hotel::class, 'hotel_user');
    }
}
