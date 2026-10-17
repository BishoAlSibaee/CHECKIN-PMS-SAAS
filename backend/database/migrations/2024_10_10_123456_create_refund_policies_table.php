<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('refund_policies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->integer('days_before_checkin');
            $table->decimal('refund_percent', 5, 2);
            $table->tinyInteger('payment_status')->default(0)->comment('0:no pay,1:partial,2:full');
            $table->tinyInteger('during_stay')->default(0)->comment('0:pre-checkin,1:during stay');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refund_policies');
    }
};

