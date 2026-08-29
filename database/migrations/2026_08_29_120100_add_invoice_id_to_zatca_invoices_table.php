<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('zatca_invoices', function (Blueprint $table) {
            $table->foreignId('invoice_id')->nullable()->after('reservation_id')->constrained('invoices')->nullOnDelete();
        });

        // Backfill: every zatca_invoices row predating this column represents a
        // reservation that already has (or should have) a matching invoices row —
        // create one from the reservation's current totals so nothing is orphaned.
        $orphans = DB::table('zatca_invoices')->whereNull('invoice_id')->get();

        foreach ($orphans as $zatcaInvoice) {
            $reservation = DB::table('reservations')->where('id', $zatcaInvoice->reservation_id)->first();
            if (! $reservation) {
                continue;
            }

            $invoiceId = DB::table('invoices')->insertGetId([
                'reservation_id' => $reservation->id,
                'invoice_number' => $zatcaInvoice->invoice_number,
                'type' => 'tax_invoice',
                'status' => $zatcaInvoice->status === 'reported' ? 'issued' : 'issued',
                'subtotal' => $reservation->subtotal ?? 0,
                'tax_amount' => $reservation->taxes ?? 0,
                'total' => $reservation->total ?? 0,
                'currency' => 'SAR',
                'line_items' => json_encode([[
                    'name' => 'Hotel stay #'.$reservation->id,
                    'quantity' => max(1, (int) ($reservation->nights ?? 1)),
                    'unit_price' => round(((float) ($reservation->subtotal ?? 0)) / max(1, (int) ($reservation->nights ?? 1)), 2),
                    'line_total' => $reservation->subtotal ?? 0,
                    'tax_amount' => $reservation->taxes ?? 0,
                ]]),
                'issued_at' => $zatcaInvoice->reported_at ?? $zatcaInvoice->created_at,
                'created_at' => $zatcaInvoice->created_at,
                'updated_at' => now(),
            ]);

            DB::table('zatca_invoices')->where('id', $zatcaInvoice->id)->update(['invoice_id' => $invoiceId]);
        }
    }

    public function down(): void
    {
        Schema::table('zatca_invoices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('invoice_id');
        });
    }
};
