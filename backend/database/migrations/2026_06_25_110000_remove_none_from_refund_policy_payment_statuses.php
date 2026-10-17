<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('refund_policies')->orderBy('id')->each(function ($row) {
            $updates = [];

            $statuses = [];
            if ($row->payment_statuses !== null) {
                $decoded = json_decode((string) $row->payment_statuses, true);
                if (is_array($decoded)) {
                    $statuses = array_values(array_filter(
                        $decoded,
                        fn ($status) => in_array($status, ['partial', 'full', 'paid'], true)
                    ));
                }
            }

            if ($statuses !== []) {
                $updates['payment_statuses'] = json_encode($statuses);
                $updates['payment_status'] = count($statuses) === 1
                    ? match ($statuses[0]) {
                        'partial' => 1,
                        'full' => 2,
                        default => null,
                    }
                    : null;
            } else {
                $updates['payment_statuses'] = null;
                if ((int) $row->payment_status === 0) {
                    $updates['payment_status'] = null;
                }
            }

            if ($updates !== []) {
                DB::table('refund_policies')->where('id', $row->id)->update($updates);
            }
        });
    }

    public function down(): void
    {
        // Irreversible cleanup — legacy "no payment" targeting is intentionally dropped.
    }
};
