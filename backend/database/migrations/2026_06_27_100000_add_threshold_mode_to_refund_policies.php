<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('refund_policies', function (Blueprint $table) {
            $table->string('threshold_mode', 20)->default('fixed_days')->after('timing');
            $table->decimal('threshold_percent', 5, 2)->nullable()->after('threshold_mode');
        });
    }

    public function down(): void
    {
        Schema::table('refund_policies', function (Blueprint $table) {
            $table->dropColumn(['threshold_mode', 'threshold_percent']);
        });
    }
};
