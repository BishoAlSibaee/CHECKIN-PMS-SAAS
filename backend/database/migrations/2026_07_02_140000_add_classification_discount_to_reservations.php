<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->decimal('classification_discount', 12, 2)->default(0)->after('discount');
            $table->unsignedBigInteger('guest_classification_id')->nullable()->after('classification_discount');
            $table->unsignedBigInteger('classification_discount_id')->nullable()->after('guest_classification_id');
        });
    }

    public function down(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->dropColumn([
                'classification_discount',
                'guest_classification_id',
                'classification_discount_id',
            ]);
        });
    }
};
