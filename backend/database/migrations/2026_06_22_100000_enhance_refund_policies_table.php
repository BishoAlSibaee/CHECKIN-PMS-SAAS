<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('refund_policies', function (Blueprint $table) {
            $table->unsignedTinyInteger('rent_type')->nullable()->after('name')
                ->comment('null=all, 0=daily, 1=monthly, 2=annual');
            $table->string('timing', 20)->default('before_start')->after('rent_type');
            $table->integer('days_threshold')->nullable()->after('timing');
            $table->string('refund_basis', 20)->default('total')->after('refund_percent');
        });

        DB::table('refund_policies')->orderBy('id')->each(function ($row) {
            DB::table('refund_policies')->where('id', $row->id)->update([
                'timing' => (int) $row->during_stay === 1 ? 'after_start' : 'before_start',
                'days_threshold' => $row->days_before_checkin,
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('refund_policies', function (Blueprint $table) {
            $table->dropColumn(['rent_type', 'timing', 'days_threshold', 'refund_basis']);
        });
    }
};
