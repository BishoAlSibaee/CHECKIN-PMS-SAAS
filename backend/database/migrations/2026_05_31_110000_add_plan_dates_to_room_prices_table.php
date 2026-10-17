<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('room_prices', function (Blueprint $table) {
            if (!Schema::hasColumn('room_prices', 'start_plan')) {
                $table->date('start_plan')->nullable()->after('min_month');
            }
            if (!Schema::hasColumn('room_prices', 'end_plan')) {
                $table->date('end_plan')->nullable()->after('start_plan');
            }
        });
    }

    public function down(): void
    {
        Schema::table('room_prices', function (Blueprint $table) {
            if (Schema::hasColumn('room_prices', 'end_plan')) {
                $table->dropColumn('end_plan');
            }
            if (Schema::hasColumn('room_prices', 'start_plan')) {
                $table->dropColumn('start_plan');
            }
        });
    }
};
