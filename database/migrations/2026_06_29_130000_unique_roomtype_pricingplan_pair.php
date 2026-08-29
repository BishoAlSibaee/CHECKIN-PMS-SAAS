<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('roomtype_pricingplan')) {
            return;
        }

        $dupes = DB::table('roomtype_pricingplan')
            ->select('roomtype_id', 'pricingplan_id', DB::raw('MIN(id) as keep_id'), DB::raw('COUNT(*) as c'))
            ->groupBy('roomtype_id', 'pricingplan_id')
            ->having('c', '>', 1)
            ->get();

        foreach ($dupes as $row) {
            DB::table('roomtype_pricingplan')
                ->where('roomtype_id', $row->roomtype_id)
                ->where('pricingplan_id', $row->pricingplan_id)
                ->where('id', '!=', $row->keep_id)
                ->delete();
        }

        Schema::table('roomtype_pricingplan', function (Blueprint $table) {
            $table->unique(['roomtype_id', 'pricingplan_id'], 'roomtype_pricingplan_unique');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('roomtype_pricingplan')) {
            return;
        }

        Schema::table('roomtype_pricingplan', function (Blueprint $table) {
            $table->dropUnique('roomtype_pricingplan_unique');
        });
    }
};
