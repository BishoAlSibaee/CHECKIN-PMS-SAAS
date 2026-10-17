<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Which staff members can access which hotels. A user can belong to more than
 * one hotel (e.g. management) — see the multi-branch plan's staff-access
 * decision. Every existing user is backfilled onto the default hotel created
 * in create_hotels_table so nobody is locked out after this migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hotel_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hotel_id')->constrained('hotels')->cascadeOnDelete();
            $table->unsignedBigInteger('user_id');
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['hotel_id', 'user_id']);
        });

        $defaultHotelId = DB::table('hotels')->orderBy('id')->value('id');
        $now = now();

        $rows = DB::table('users')->pluck('id')->map(fn ($userId) => [
            'hotel_id' => $defaultHotelId,
            'user_id' => $userId,
            'created_at' => $now,
            'updated_at' => $now,
        ])->all();

        if ($rows !== []) {
            DB::table('hotel_user')->insert($rows);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('hotel_user');
    }
};
