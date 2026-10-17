<?php

namespace Tests\Feature\Accounting;

use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\Reservation;
use App\Support\ReservationRelatedCleanup;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PurgeOrphanReservationDataTest extends TestCase
{
    public function test_purge_orphans_removes_journal_and_logs_for_missing_reservation(): void
    {
        $cash = $this->ensureAccount('1010', 'Cash / Bank', 'asset');
        $ar = $this->ensureAccount('1100', 'Accounts Receivable', 'asset');

        $missingReservationId = 9_999_999_91;
        $this->assertFalse(Reservation::query()->whereKey($missingReservationId)->exists());

        $reference = 'PAY-ORPHAN-PURGE-'.uniqid();
        $entry = JournalEntry::query()->create([
            'entry_date' => '2026-07-16',
            'reference' => $reference,
            'description' => 'Guest payment',
            'user_id' => null,
            'source_type' => 'reservation_pay',
            'source_id' => 9_999_999_92,
        ]);

        JournalEntryLine::query()->create([
            'journal_entry_id' => $entry->id,
            'account_id' => $cash->id,
            'debit' => 75,
            'credit' => 0,
            'reservation_id' => $missingReservationId,
            'memo' => 'Payment',
        ]);
        JournalEntryLine::query()->create([
            'journal_entry_id' => $entry->id,
            'account_id' => $ar->id,
            'debit' => 0,
            'credit' => 75,
            'reservation_id' => $missingReservationId,
            'memo' => 'Payment',
        ]);

        $changeLogId = null;
        if (Schema::hasTable('reservation_change_logs')) {
            $changeLogId = DB::table('reservation_change_logs')->insertGetId([
                'reservation_id' => $missingReservationId,
                'user_id' => null,
                'action' => 'update',
                'old_values' => null,
                'new_values' => null,
                'changes' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        try {
            $counts = ReservationRelatedCleanup::purgeOrphansForMissingReservations();

            $this->assertGreaterThan(0, $counts['accounting_rows'] ?? 0);
            $this->assertFalse(JournalEntry::query()->whereKey($entry->id)->exists());
            $this->assertSame(0, ReservationRelatedCleanup::countOrphanJournalLines());

            if ($changeLogId !== null) {
                $this->assertFalse(
                    DB::table('reservation_change_logs')->where('id', $changeLogId)->exists()
                );
            }
        } finally {
            JournalEntryLine::query()->where('journal_entry_id', $entry->id)->delete();
            JournalEntry::query()->whereKey($entry->id)->delete();
            if ($changeLogId !== null) {
                DB::table('reservation_change_logs')->where('id', $changeLogId)->delete();
            }
        }
    }

    public function test_purge_related_keeps_reservation_row(): void
    {
        $cash = $this->ensureAccount('1010', 'Cash / Bank', 'asset');
        $ar = $this->ensureAccount('1100', 'Accounts Receivable', 'asset');
        $reservationId = 9_999_999_94;

        $reference = 'PAY-RELATED-PURGE-'.uniqid();
        $entry = JournalEntry::query()->create([
            'entry_date' => '2026-07-16',
            'reference' => $reference,
            'description' => 'Guest payment',
            'user_id' => null,
            'source_type' => 'reservation_pay',
            'source_id' => 9_999_999_95,
        ]);

        JournalEntryLine::query()->create([
            'journal_entry_id' => $entry->id,
            'account_id' => $cash->id,
            'debit' => 20,
            'credit' => 0,
            'reservation_id' => $reservationId,
            'memo' => 'Payment',
        ]);
        JournalEntryLine::query()->create([
            'journal_entry_id' => $entry->id,
            'account_id' => $ar->id,
            'debit' => 0,
            'credit' => 20,
            'reservation_id' => $reservationId,
            'memo' => 'Payment',
        ]);

        try {
            ReservationRelatedCleanup::purgeRelated([$reservationId]);

            $this->assertFalse(JournalEntry::query()->whereKey($entry->id)->exists());
        } finally {
            JournalEntryLine::query()->where('journal_entry_id', $entry->id)->delete();
            JournalEntry::query()->whereKey($entry->id)->delete();
        }
    }

    private function ensureAccount(string $code, string $name, string $type): ChartOfAccount
    {
        $account = ChartOfAccount::query()->where('code', $code)->first();
        if ($account) {
            return $account;
        }

        return ChartOfAccount::query()->create([
            'code' => $code,
            'name_en' => $name,
            'name_ar' => $name,
            'type' => $type,
            'is_active' => true,
        ]);
    }
}
