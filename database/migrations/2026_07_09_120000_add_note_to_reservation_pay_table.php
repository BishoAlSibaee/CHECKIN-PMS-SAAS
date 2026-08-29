<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reservation_pay', function (Blueprint $table) {
            if (! Schema::hasColumn('reservation_pay', 'note')) {
                $table->string('note', 500)->nullable()->after('type');
            }
        });
    }

    public function down(): void
    {
        Schema::table('reservation_pay', function (Blueprint $table) {
            if (Schema::hasColumn('reservation_pay', 'note')) {
                $table->dropColumn('note');
            }
        });
    }
};
