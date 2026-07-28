<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('zatca_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reservation_id')->constrained('reservations')->cascadeOnDelete();
            $table->string('invoice_number', 64);
            $table->uuid('uuid');
            $table->string('invoice_hash', 128)->nullable();
            $table->unsignedInteger('icv')->nullable();
            $table->string('status', 32)->default('pending');
            $table->string('reporting_status', 64)->nullable();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->string('signed_xml_path')->nullable();
            $table->json('response_payload')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('reported_at')->nullable();
            $table->timestamps();

            $table->unique('reservation_id');
            $table->unique('uuid');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('zatca_invoices');
    }
};
