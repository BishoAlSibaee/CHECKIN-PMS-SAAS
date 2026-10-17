<?php

use App\Models\Hotel;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A dedicated service account the booking-ingestion job authenticates as
 * (Auth::loginUsingId()) so reservations created from an incoming Channex
 * booking have a real user_id for audit/reporting — never used for an
 * actual interactive login. Granted access to every hotel since an incoming
 * booking can target any connected property.
 */
return new class extends Migration
{
    public function up(): void
    {
        $user = User::firstOrCreate(
            ['job_number' => 'CHANNEX-SVC'],
            [
                'name' => 'Channex Integration',
                'mobile' => '0500000000',
                'email' => 'channex-integration@system.local',
                'active' => 1,
                'password' => bcrypt((string) \Illuminate\Support\Str::random(40)),
            ]
        );

        foreach (Hotel::pluck('id') as $hotelId) {
            DB::table('hotel_user')->updateOrInsert(
                ['hotel_id' => $hotelId, 'user_id' => $user->id],
                ['created_at' => now(), 'updated_at' => now()]
            );
        }
    }

    public function down(): void
    {
        $user = User::where('job_number', 'CHANNEX-SVC')->first();
        if ($user) {
            DB::table('hotel_user')->where('user_id', $user->id)->delete();
            $user->delete();
        }
    }
};
