<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('reservations') || ! Schema::hasColumn('reservations', 'login_time')) {
            return;
        }

        Schema::table('reservations', function (Blueprint $table) {
            $table->date('login_time')->nullable()->change();
        });

        if (Schema::hasTable('reservation_change_logs')) {
            DB::table('reservations as r')
                ->where('r.logedin', 0)
                ->whereNotNull('r.login_time')
                ->whereNotExists(function ($query) {
                    $query->select(DB::raw(1))
                        ->from('reservation_change_logs as cl')
                        ->whereColumn('cl.reservation_id', 'r.id')
                        ->where('cl.action', 'checked_in');
                })
                ->update(['login_time' => null]);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('reservations') || ! Schema::hasColumn('reservations', 'login_time')) {
            return;
        }

        DB::table('reservations')
            ->whereNull('login_time')
            ->update(['login_time' => DB::raw('start_date')]);

        Schema::table('reservations', function (Blueprint $table) {
            $table->date('login_time')->nullable(false)->change();
        });
    }
};
