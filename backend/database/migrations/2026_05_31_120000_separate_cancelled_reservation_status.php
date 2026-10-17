<?php

use App\Models\Reservation;
use App\Models\ReservationPay;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('reservations')
            ->where('reservation_status', Reservation::STATUS_PENDING_PAYMENT)
            ->whereIn('id', function ($query) {
                $query->select('reservation_id')
                    ->from('reservation_pay')
                    ->where('type', ReservationPay::TYPE_REFUND);
            })
            ->update(['reservation_status' => Reservation::STATUS_CANCELLED]);
    }

    public function down(): void
    {
        DB::table('reservations')
            ->where('reservation_status', Reservation::STATUS_CANCELLED)
            ->update(['reservation_status' => Reservation::STATUS_PENDING_PAYMENT]);
    }
};
