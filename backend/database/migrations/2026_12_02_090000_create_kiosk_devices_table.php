<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kiosk_devices', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->foreignId('building_id')->constrained('buildings');
            $table->foreignId('user_id')->constrained('users');
            $table->foreignId('reservation_source_id')->nullable()->constrained('reservation_sources')->nullOnDelete();
            $table->foreignId('default_stay_reason_id')->nullable()->constrained('stay_reasons')->nullOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->timestamp('last_used_at')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kiosk_devices');
    }
};
