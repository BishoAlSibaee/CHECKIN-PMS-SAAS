<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Separate from the "users" password_reset_tokens table — same reasoning
 * as PlatformAdmin being a separate model/guard entirely: never let a
 * reset token collide across the two completely unrelated account spaces.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_admin_password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_admin_password_reset_tokens');
    }
};
