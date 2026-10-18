<?php

namespace Tests\Feature\Accounting;

use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\Reservation;
use App\Services\Accounting\FinancialAuditService;
use Tests\TestCase;

class FinancialAuditStaleReservationLinkTest extends TestCase
{
    use \Illuminate\Foundation\Testing\DatabaseTransactions;

    protected function connectionsToTransact(): array
    {
        return ['mysql', 'mysql2'];
    }

    public function test_audit_log_omits_reservation_link_when_reservation_was_deleted(): void
    {
        $cash = $this->ensureAccount('1010', 'Cash / Bank', 'asset');
        $ar = $this->ensureAccount('1100', 'Accounts Receivable', 'asset');

        $missingReservationId = 9_999_999_91;
        $this->assertFalse(Reservation::query()->whereKey($missingReservationId)->exists());

        $reference = 'PAY-STALE-'.uniqid();
        $entry = JournalEntry::query()->create([
            'entry_date' => '2026-07-15',
            'reference' => $reference,
            'description' => 'Guest payment',
            'user_id' => null,
            'source_type' => 'reservation_pay',
            'source_id' => 9_999_999_92,
        ]);

        JournalEntryLine::query()->create([
            'journal_entry_id' => $entry->id,
            'account_id' => $cash->id,
            'debit' => 100,
            'credit' => 0,
            'reservation_id' => $missingReservationId,
            'memo' => 'Payment',
        ]);
        JournalEntryLine::query()->create([
            'journal_entry_id' => $entry->id,
            'account_id' => $ar->id,
            'debit' => 0,
            'credit' => 100,
            'reservation_id' => $missingReservationId,
            'memo' => 'Payment',
        ]);

        try {
            $report = app(FinancialAuditService::class)->listForReport('2026-07-01', '2026-07-31', 500);
            $row = collect($report['rows'])->first(
                fn (array $item) => ($item['reference'] ?? '') === $reference
            );

            $this->assertNotNull($row, 'Expected stale payment journal row in audit log');
            $this->assertNull($row['reservation_id'], 'Stale reservation ids must not be clickable');
            $this->assertSame('journal-entries', $row['detail_slug']);
            $this->assertSame('payment_posted', $row['operation']);
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
