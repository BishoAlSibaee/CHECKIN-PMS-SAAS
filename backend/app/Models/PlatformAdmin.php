<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * Deliberately NOT App\Models\User. Platform operators (who onboard and
 * manage tenants/SaaS customers) authenticate through their own guard
 * ("platform_admin" in config/auth.php) against this separate table, so no
 * account belonging to any tenant's hotel can ever reach the platform-admin
 * app — regardless of what permissions that tenant's admin role has.
 */
class PlatformAdmin extends Authenticatable
{
    use HasApiTokens, Notifiable;

    protected $fillable = ['name', 'email', 'password', 'is_active'];

    protected $hidden = ['password', 'remember_token'];

    protected $casts = [
        'is_active' => 'boolean',
    ];
}
