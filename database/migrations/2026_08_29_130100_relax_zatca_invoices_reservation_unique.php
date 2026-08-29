<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A reservation can now have more than one zatca_invoices row (the original tax
 * invoice AND a later credit note each get their own reporting record) — the
 * true 1:1 relationship is with a specific `invoices` row, not the reservation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('zatca_invoices', function (Blueprint $table) {
            // MySQL/InnoDB won't drop a unique index that's backing a foreign key,
            // so the FK has to go first, then get re-added as a plain (non-unique) index.
            $table->dropForeign(['reservation_id']);
            $table->dropUnique(['reservation_id']);
            $table->foreign('reservation_id')->references('id')->on('reservations')->cascadeOnDelete();
            $table->unique('invoice_id');
        });
    }

    public function down(): void
    {
        Schema::table('zatca_invoices', function (Blueprint $table) {
            $table->dropUnique(['invoice_id']);
            $table->dropForeign(['reservation_id']);
            $table->unique('reservation_id');
            $table->foreign('reservation_id')->references('id')->on('reservations')->cascadeOnDelete();
        });
    }
};
