<?php

namespace App\Support;

use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\ReservationDailyCharge;
use App\Models\ReservationExtend;
use App\Models\ReservationPay;
use App\Models\ReservationPenalty;
use App\Models\ReservationRoom;
use App\Models\ReservationTax;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ReservationRelatedCleanup
{
    /**
     * Remove accounting rows tied to reservations before deleting reservation rows.
     *
     * @param  Collection<int, int>|array<int, int>  $reservationIds
     */
    public static function purgeAccounting(Collection|array $reservationIds): int
    {
        $ids = collect($reservationIds)->filter()->unique()->values();
        if ($ids->isEmpty()) {
            return 0;
        }

        $payIds = ReservationPay::query()->whereIn('reservation_id', $ids)->pluck('id');
        $chargeIds = ReservationDailyCharge::query()->whereIn('reservation_id', $ids)->pluck('id');

        $entryIds = JournalEntryLine::query()
            ->whereIn('reservation_id', $ids)
            ->pluck('journal_entry_id');

        if ($payIds->isNotEmpty()) {
            $entryIds = $entryIds->merge(
                JournalEntry::query()
                    ->where('source_type', 'reservation_pay')
                    ->whereIn('source_id', $payIds)
                    ->pluck('id')
            );
        }

        if ($chargeIds->isNotEmpty()) {
            $entryIds = $entryIds->merge(
                JournalEntry::query()
                    ->where('source_type', 'daily_charge')
                    ->whereIn('source_id', $chargeIds)
                    ->pluck('id')
            );
        }

        $entryIds = $entryIds->unique()->values();
        $removed = 0;

        if ($entryIds->isNotEmpty()) {
            $removed += JournalEntryLine::query()->whereIn('journal_entry_id', $entryIds)->delete();
            $removed += JournalEntry::query()->whereIn('id', $entryIds)->delete();
        }

        $removed += JournalEntryLine::query()
            ->whereIn('reservation_id', $ids)
            ->delete();

        return $removed;
    }

    /**
     * Delete every transactional row tied to the given reservation IDs
     * (accounting, pays, charges, taxes, penalties, rooms, logs).
     * Does not delete the reservation row itself.
     *
     * @param  Collection<int, int>|array<int, int>  $reservationIds
     * @return array<string, int>
     */
    public static function purgeRelated(Collection|array $reservationIds): array
    {
        $ids = collect($reservationIds)
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $id > 0)
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return [];
        }

        return DB::transaction(function () use ($ids) {
            $counts = [];

            $counts['accounting_rows'] = self::purgeAccounting($ids);

            if (Schema::hasTable('room_prices')) {
                $reservationRoomIds = ReservationRoom::query()
                    ->whereIn('reservation_id', $ids)
                    ->pluck('id');
                $counts['room_prices'] = $reservationRoomIds->isEmpty()
                    ? 0
                    : (int) DB::table('room_prices')->whereIn('reservation_room_id', $reservationRoomIds)->delete();
            }

            if (Schema::hasTable('reservation_change_logs')) {
                $counts['change_logs'] = (int) DB::table('reservation_change_logs')
                    ->whereIn('reservation_id', $ids)
                    ->delete();
            }

            if (Schema::hasTable('financial_audit_logs')) {
                $auditDeleted = (int) DB::table('financial_audit_logs')
                    ->where('entity_type', 'reservation')
                    ->whereIn('entity_id', $ids)
                    ->delete();

                $payIds = ReservationPay::query()->whereIn('reservation_id', $ids)->pluck('id');
                if ($payIds->isNotEmpty()) {
                    $auditDeleted += (int) DB::table('financial_audit_logs')
                        ->where('entity_type', 'reservation_pay')
                        ->whereIn('entity_id', $payIds)
                        ->delete();
                }

                $counts['financial_audit_logs'] = $auditDeleted;
            }

            $counts['daily_charges'] = ReservationDailyCharge::query()->whereIn('reservation_id', $ids)->delete();
            $counts['pays'] = ReservationPay::query()->whereIn('reservation_id', $ids)->delete();
            $counts['extends'] = ReservationExtend::query()->whereIn('reservation_id', $ids)->delete();
            $counts['taxes'] = ReservationTax::query()->whereIn('reservation_id', $ids)->delete();
            $counts['penalties'] = ReservationPenalty::query()->whereIn('reservation_id', $ids)->delete();
            $counts['rooms'] = ReservationRoom::query()->whereIn('reservation_id', $ids)->delete();

            return $counts;
        });
    }

    /**
     * Purge all rows that still reference reservation IDs that no longer exist,
     * plus journal entries whose payment/charge source was already deleted.
     *
     * @return array<string, int>
     */
    public static function purgeOrphansForMissingReservations(): array
    {
        return DB::transaction(function () {
            $ids = self::collectMissingReservationIdsReferenced();

            $counts = self::purgeRelated($ids);
            $counts['missing_reservation_ids'] = $ids->count();

            $sourcePurge = self::purgeJournalEntriesWithMissingSources();
            foreach ($sourcePurge as $key => $value) {
                $counts[$key] = ($counts[$key] ?? 0) + $value;
            }

            $repair = self::repairOrphanJournalLines();
            $counts['orphan_lines_final'] = $repair['lines_deleted'];
            $counts['empty_entries_final'] = $repair['entries_deleted'];

            return $counts;
        });
    }

    /**
     * @return Collection<int, int>
     */
    public static function collectMissingReservationIdsReferenced(): Collection
    {
        $ids = collect();

        $childTables = [
            'reservation_pay',
            'reservation_daily_charges',
            'reservation_rooms',
            'reservation_extend',
            'reservation_taxes',
            'reservation_penalties',
        ];

        foreach ($childTables as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $ids = $ids->merge(
                DB::table($table)
                    ->leftJoin('reservations', "{$table}.reservation_id", '=', 'reservations.id')
                    ->whereNull('reservations.id')
                    ->whereNotNull("{$table}.reservation_id")
                    ->distinct()
                    ->pluck("{$table}.reservation_id")
            );
        }

        if (Schema::hasTable('reservation_change_logs')) {
            $ids = $ids->merge(
                DB::table('reservation_change_logs')
                    ->leftJoin('reservations', 'reservation_change_logs.reservation_id', '=', 'reservations.id')
                    ->whereNull('reservations.id')
                    ->whereNotNull('reservation_change_logs.reservation_id')
                    ->distinct()
                    ->pluck('reservation_change_logs.reservation_id')
            );
        }

        if (Schema::hasTable('financial_audit_logs')) {
            $ids = $ids->merge(
                DB::table('financial_audit_logs')
                    ->leftJoin('reservations', 'financial_audit_logs.entity_id', '=', 'reservations.id')
                    ->where('financial_audit_logs.entity_type', 'reservation')
                    ->whereNull('reservations.id')
                    ->whereNotNull('financial_audit_logs.entity_id')
                    ->distinct()
                    ->pluck('financial_audit_logs.entity_id')
            );
        }

        if (Schema::hasTable('journal_entry_lines')) {
            $ids = $ids->merge(
                DB::table('journal_entry_lines')
                    ->leftJoin('reservations', 'journal_entry_lines.reservation_id', '=', 'reservations.id')
                    ->whereNotNull('journal_entry_lines.reservation_id')
                    ->whereNull('reservations.id')
                    ->distinct()
                    ->pluck('journal_entry_lines.reservation_id')
            );
        }

        return $ids->map(fn ($id) => (int) $id)->filter(fn (int $id) => $id > 0)->unique()->values();
    }

    /**
     * @return array{entries_deleted: int, lines_deleted: int}
     */
    public static function purgeJournalEntriesWithMissingSources(): array
    {
        $entryIds = collect();

        if (Schema::hasTable('journal_entries') && Schema::hasTable('reservation_pay')) {
            $entryIds = $entryIds->merge(
                DB::table('journal_entries as je')
                    ->leftJoin('reservation_pay as rp', 'je.source_id', '=', 'rp.id')
                    ->where('je.source_type', 'reservation_pay')
                    ->whereNull('rp.id')
                    ->pluck('je.id')
            );
        }

        if (Schema::hasTable('journal_entries') && Schema::hasTable('reservation_daily_charges')) {
            $entryIds = $entryIds->merge(
                DB::table('journal_entries as je')
                    ->leftJoin('reservation_daily_charges as dc', 'je.source_id', '=', 'dc.id')
                    ->where('je.source_type', 'daily_charge')
                    ->whereNull('dc.id')
                    ->pluck('je.id')
            );
        }

        $entryIds = $entryIds->unique()->values();
        if ($entryIds->isEmpty()) {
            return ['entries_deleted' => 0, 'lines_deleted' => 0];
        }

        $linesDeleted = JournalEntryLine::query()->whereIn('journal_entry_id', $entryIds)->delete();
        $entriesDeleted = JournalEntry::query()->whereIn('id', $entryIds)->delete();

        return [
            'entries_deleted' => $entriesDeleted,
            'lines_deleted' => $linesDeleted,
        ];
    }

    /**
     * Delete journal lines whose reservation_id points to a missing reservation.
     * Removes journal entries left without lines.
     */
    public static function repairOrphanJournalLines(): array
    {
        $orphanLineIds = DB::table('journal_entry_lines')
            ->leftJoin('reservations', 'journal_entry_lines.reservation_id', '=', 'reservations.id')
            ->whereNotNull('journal_entry_lines.reservation_id')
            ->whereNull('reservations.id')
            ->pluck('journal_entry_lines.id');

        $linesDeleted = 0;
        if ($orphanLineIds->isNotEmpty()) {
            $linesDeleted = JournalEntryLine::query()->whereIn('id', $orphanLineIds)->delete();
        }

        $emptyEntries = JournalEntry::query()
            ->whereDoesntHave('lines')
            ->pluck('id');

        $entriesDeleted = 0;
        if ($emptyEntries->isNotEmpty()) {
            $entriesDeleted = JournalEntry::query()->whereIn('id', $emptyEntries)->delete();
        }

        return [
            'lines_deleted' => $linesDeleted,
            'entries_deleted' => $entriesDeleted,
        ];
    }

    public static function countOrphanJournalLines(): int
    {
        return (int) DB::table('journal_entry_lines')
            ->leftJoin('reservations', 'journal_entry_lines.reservation_id', '=', 'reservations.id')
            ->whereNotNull('journal_entry_lines.reservation_id')
            ->whereNull('reservations.id')
            ->count();
    }
}
