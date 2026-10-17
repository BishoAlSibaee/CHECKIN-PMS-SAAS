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
            $table->json('payment_statuses')->nullable()->after('payment_status')
                ->comment('none, partial, full, paid — empty/null matches any');
        });

        DB::table('refund_policies')->orderBy('id')->each(function ($row) {
            if ($row->payment_status === null) {
                return;
            }

            $status = match ((int) $row->payment_status) {
                0 => ['none'],
                1 => ['partial'],
                2 => ['full'],
                default => [],
            };

            if ($status !== []) {
                DB::table('refund_policies')->where('id', $row->id)->update([
                    'payment_statuses' => json_encode($status),
                ]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('refund_policies', function (Blueprint $table) {
            $table->dropColumn('payment_statuses');
        });
    }
};
