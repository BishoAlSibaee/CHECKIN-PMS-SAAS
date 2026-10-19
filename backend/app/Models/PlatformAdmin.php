<?php

namespace App\Models;

use App\Mail\PlatformAdminResetPasswordMail;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Mail;
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

    /**
     * This app doesn't use Laravel's Notification system anywhere (see
     * AppNotification's own docblock) — send a plain Mailable instead of
     * the default ResetPassword notification, and point it at the
     * standalone PlatformAdmin Angular app's own reset page, since this
     * backend has no web route for that.
     */
    public function sendPasswordResetNotification($token): void
    {
        Mail::to($this->email)->send(new PlatformAdminResetPasswordMail($token, $this->email));
    }
}
