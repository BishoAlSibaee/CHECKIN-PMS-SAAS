<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reservation_id')->constrained('reservations')->cascadeOnDelete();
            $table->foreignId('related_invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            // Nullable: assigned right after insert using the row's own auto-increment
            // id (INV-000001 style), so it's briefly null between the two writes.
            $table->string('invoice_number', 32)->nullable()->unique();
            $table->string('type', 20)->default('tax_invoice');
            $table->string('status', 20)->default('issued');
            $table->decimal('subtotal', 12, 2);
            $table->decimal('tax_amount', 12, 2);
            $table->decimal('total', 12, 2);
            $table->string('currency', 3)->default('SAR');
            $table->json('line_items')->nullable();
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('issued_at');
            $table->timestamps();

            $table->index('reservation_id');
            $table->index(['type', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
