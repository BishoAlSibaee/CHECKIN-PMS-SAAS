<?php

namespace Tests\Feature\Accounting;

use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\Reservation;
use App\Services\Reports\ReportQueryService;
use Tests\TestCase;

class JournalEntriesStaleReservationLinkTest extends TestCase
{
    use \Illuminate\Foundation\Testing\DatabaseTransactions;

    protected function connectionsToTransact(): array
    {
        return ['mysql', 'mysql2'];
    }

    public function test_journal_entries_report_clears_deleted_reservation_links(): void
    {
        $cash = $this->ensureAccount('1010', 'Cash / Bank', 'asset');
        $ar = $this->ensureAccount('1100', 'Accounts Receivable', 'asset');

        $missingReservationId = 9_999_999_81;
        $this->assertFalse(Reservation::query()->whereKey($missingReservationId)->exists());

        $reference = 'PAY-JE-STALE-'.uniqid();
        $entry = JournalEntry::query()->create([
            'entry_date' => '2026-07-16',
            'reference' => $reference,
            'description' => 'Guest payment',
            'user_id' => null,
            'source_type' => 'reservation_pay',
            'source_id' => 9_999_999_82,
        ]);

        JournalEntryLine::query()->create([
            'journal_entry_id' => $entry->id,
            'account_id' => $cash->id,
            'debit' => 50,
            'credit' => 0,
            'reservation_id' => $missingReservationId,
            'memo' => 'Payment',
        ]);
        JournalEntryLine::query()->create([
            'journal_entry_id' => $entry->id,
            'account_id' => $ar->id,
            'debit' => 0,
            'credit' => 50,
            'reservation_id' => $missingReservationId,
            'memo' => 'Payment',
        ]);

        try {
            $report = app(ReportQueryService::class)->run('journal-entries', [
                'start_date' => '2026-07-01',
                'end_date' => '2026-07-31',
            ]);

            $rows = collect($report['rows'] ?? [])->filter(
                fn (array $row) => ($row['reference'] ?? '') === $reference
            );

            $this->assertGreaterThan(0, $rows->count(), 'Expected journal lines for stale payment');
            foreach ($rows as $row) {
                $this->assertNull($row['reservation_id'], 'Deleted reservation must not remain clickable');
                $this->assertSame('Guest payment', $row['description']);
            }
        } finally {
            $entry->lines()->delete();
            $entry->delete();
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
            'active' => 1,
        ]);
    }
}
