<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Deliberately a separate table from `users` — platform operators (who
 * manage tenants/SaaS customers) must stay unreachable through the main
 * hotel app's login no matter what permissions a tenant's own admin role
 * ends up with. See App\Models\PlatformAdmin and config/auth.php's
 * "platform_admin" guard (Sanctum, not Passport — its personal_access_tokens
 * table is polymorphic, so tokens for this model never collide with
 * Passport's user-id-keyed oauth tokens).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_admins', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->boolean('is_active')->default(true);
            $table->rememberToken();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_admins');
    }
};
