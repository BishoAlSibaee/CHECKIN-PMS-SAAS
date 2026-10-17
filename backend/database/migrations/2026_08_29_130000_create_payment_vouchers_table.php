<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_vouchers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reservation_pay_id')->unique()->constrained('reservation_pay')->cascadeOnDelete();
            $table->string('voucher_number', 32)->nullable()->unique();
            // 'receipt' = سند قبض (money received), 'disbursement' = سند صرف (money paid out).
            $table->string('type', 20);
            $table->decimal('amount', 12, 2);
            $table->string('method', 30)->nullable();
            $table->timestamp('issued_at');
            $table->timestamps();

            $table->index('type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_vouchers');
    }
};
