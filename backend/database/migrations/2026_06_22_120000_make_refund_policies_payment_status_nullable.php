<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('refund_policies', function (Blueprint $table) {
            $table->tinyInteger('payment_status')->nullable()->default(null)->change();
        });
    }

    public function down(): void
    {
        Schema::table('refund_policies', function (Blueprint $table) {
            $table->tinyInteger('payment_status')->default(0)->nullable(false)->change();
        });
    }
};
