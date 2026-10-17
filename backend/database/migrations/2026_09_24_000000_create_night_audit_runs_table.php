<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A permanent record of each night audit run — the daily reconciliation pass
 * (occupancy, financial integrity, ZATCA reporting) for one hotel and one
 * operational day. Re-running the same hotel/day overwrites the previous
 * result (see the unique index) rather than accumulating duplicates.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('night_audit_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hotel_id')->constrained('hotels')->cascadeOnDelete();
            $table->date('audit_date');
            $table->json('metrics');
            $table->json('exceptions');
            $table->unsignedInteger('exceptions_count')->default(0);
            $table->timestamp('generated_at');
            $table->timestamps();

            $table->unique(['hotel_id', 'audit_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('night_audit_runs');
    }
};
