<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->index('reservation_status', 'reservations_status_idx');
            $table->index('start_date', 'reservations_start_date_idx');
            $table->index('expire_date', 'reservations_expire_date_idx');
            $table->index('logedin', 'reservations_logedin_idx');
            $table->index(
                ['reservation_status', 'start_date', 'expire_date'],
                'reservations_status_dates_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->dropIndex('reservations_status_idx');
            $table->dropIndex('reservations_start_date_idx');
            $table->dropIndex('reservations_expire_date_idx');
            $table->dropIndex('reservations_logedin_idx');
            $table->dropIndex('reservations_status_dates_idx');
        });
    }
};
