<?php

namespace App\Services;

use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\Reservation;
use App\Models\ReservationDailyCharge;
use App\Models\ReservationExtend;
use App\Models\ReservationPay;
use App\Models\ReservationPenalty;
use App\Models\ReservationRoom;
use App\Models\ReservationTax;
use App\Models\Room;
use App\Models\RoomPrice;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Removes all reservation transactional data while keeping users, RBAC, and master data.
 */
class ReservationDataCleaner
{
    public function purge(): array
    {
        $counts = [];

        DB::transaction(function () use (&$counts) {
            $reservationRoomIds = ReservationRoom::pluck('id');

            if (Schema::hasTable('room_prices') && $reservationRoomIds->isNotEmpty()) {
                $counts['room_prices'] = RoomPrice::whereIn('reservation_room_id', $reservationRoomIds)->delete();
            }

            $counts['journal_entry_lines'] = JournalEntryLine::query()->delete();
            $counts['journal_entries'] = JournalEntry::query()->delete();

            if (Schema::hasTable('financial_audit_logs')) {
                $counts['financial_audit_logs'] = DB::table('financial_audit_logs')->delete();
            }

            if (Schema::hasTable('reservation_change_logs')) {
                $counts['reservation_change_logs'] = DB::table('reservation_change_logs')->delete();
            }

            $counts['reservation_daily_charges'] = ReservationDailyCharge::query()->delete();
            $counts['reservation_pay'] = ReservationPay::query()->delete();
            $counts['reservation_extend'] = ReservationExtend::query()->delete();
            $counts['reservation_taxes'] = ReservationTax::query()->delete();
            $counts['reservation_penalties'] = ReservationPenalty::query()->delete();
            $counts['reservation_rooms'] = ReservationRoom::query()->delete();
            $counts['reservations'] = Reservation::query()->delete();

            $counts['rooms_reset'] = Room::where('roomStatus', '!=', 4)->update(['roomStatus' => 1]);
        });

        return $counts;
    }
}
