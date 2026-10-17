<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reservation_pay', function (Blueprint $table) {
            $table->string('terminal_reference')->nullable()->unique()->after('type');
            $table->string('payment_source')->nullable()->after('terminal_reference');
            $table->string('card_last4', 4)->nullable()->after('payment_source');
        });
    }

    public function down(): void
    {
        Schema::table('reservation_pay', function (Blueprint $table) {
            $table->dropUnique(['terminal_reference']);
            $table->dropColumn(['terminal_reference', 'payment_source', 'card_last4']);
        });
    }
};
