<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('reservation_change_logs')) {
            return;
        }

        Schema::create('reservation_change_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('reservation_id')->index();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('action', 64);
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->json('changes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reservation_change_logs');
    }
};
