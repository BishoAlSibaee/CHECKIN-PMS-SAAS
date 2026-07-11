<?php

namespace Tests\Feature\Accounting;

use App\Models\ChartOfAccount;
use App\Models\Client;
use App\Models\JournalEntryLine;
use App\Models\Reservation;
use App\Models\Reservation_source;
use App\Models\ReservationPay;
use App\Models\ReservationRoom;
use App\Models\Room;
use App\Models\Stay_reason;
use App\Services\Accounting\AccountingPostingService;
use App\Services\Accounting\FinancialStatementService;
use Carbon\Carbon;
use Database\Seeders\ChartOfAccountsSeeder;
use Tests\TestCase;

class AccountingRefundPostingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-08-20 12:00:00');
        $this->seedChartOfAccounts();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_refund_posts_debit_to_accounts_receivable(): void
    {
        $refund = $this->createRefundPay(275.50);

        $entry = app(AccountingPostingService::class)->postPayment($refund);
        $this->assertNotNull($entry);

        $arAccount = ChartOfAccount::where('code', '1100')->firstOrFail();
        $cashAccount = ChartOfAccount::where('code', '1010')->firstOrFail();

        $this->assertDatabaseHas('journal_entry_lines', [
            'journal_entry_id' => $entry->id,
            'account_id' => $arAccount->id,
            'reservation_id' => $refund->reservation_id,
            'debit' => 275.50,
            'credit' => 0,
        ]);
        $this->assertDatabaseHas('journal_entry_lines', [
            'journal_entry_id' => $entry->id,
            'account_id' => $cashAccount->id,
            'reservation_id' => $refund->reservation_id,
            'debit' => 0,
            'credit' => 275.50,
        ]);

        $refundsAccount = ChartOfAccount::where('code', '5020')->firstOrFail();
        $this->assertDatabaseMissing('journal_entry_lines', [
            'journal_entry_id' => $entry->id,
            'account_id' => $refundsAccount->id,
        ]);
    }

    public function test_refund_balance_appears_on_chart_of_accounts_ar_account(): void
    {
        $start = Carbon::parse('2026-08-01');
        $end = Carbon::parse('2026-08-31');
        $service = app(FinancialStatementService::class);
        $before = round((float) ($service->accountBalances($start, $end)->firstWhere('code', '1100')->balance ?? 0), 2);

        $refund = $this->createRefundPay(180.00);
        app(AccountingPostingService::class)->postPayment($refund);

        $after = round((float) ($service->accountBalances($start, $end)->firstWhere('code', '1100')->balance ?? 0), 2);

        $this->assertEqualsWithDelta(180.0, $after - $before, 0.01);
    }

    public function test_refund_general_ledger_line_includes_reservation_id(): void
    {
        $refund = $this->createRefundPay(120.00);
        app(AccountingPostingService::class)->postPayment($refund);

        $arAccount = ChartOfAccount::where('code', '1100')->firstOrFail();
        $ledger = app(FinancialStatementService::class)->generalLedger(
            Carbon::parse('2026-08-01'),
            Carbon::parse('2026-08-31'),
            $arAccount->id,
            'asset'
        );

        $line = collect($ledger['lines'])->firstWhere('reference', 'PAY-'.$refund->id);
        $this->assertNotNull($line);
        $this->assertSame((int) $refund->reservation_id, $line['reservation_id']);
    }

    public function test_legacy_refund_journal_is_reposted_to_accounts_receivable(): void
    {
        $refund = $this->createRefundPay(90.00);
        $posting = app(AccountingPostingService::class);

        $legacy = $posting->postPayment($refund);
        $this->assertNotNull($legacy);

        $refundsAccount = ChartOfAccount::where('code', '5020')->firstOrFail();
        $legacy->lines()->delete();
        JournalEntryLine::create([
            'journal_entry_id' => $legacy->id,
            'account_id' => $refundsAccount->id,
            'debit' => 90,
            'credit' => 0,
            'reservation_id' => $refund->reservation_id,
            'memo' => 'Refund',
        ]);
        JournalEntryLine::create([
            'journal_entry_id' => $legacy->id,
            'account_id' => ChartOfAccount::where('code', '1010')->value('id'),
            'debit' => 0,
            'credit' => 90,
            'reservation_id' => $refund->reservation_id,
            'memo' => 'Refund',
        ]);

        $reposted = $posting->postPayment($refund);
        $this->assertNotNull($reposted);

        $arAccount = ChartOfAccount::where('code', '1100')->firstOrFail();
        $this->assertDatabaseHas('journal_entry_lines', [
            'journal_entry_id' => $reposted->id,
            'account_id' => $arAccount->id,
            'debit' => 90,
            'credit' => 0,
        ]);
    }

    private function seedChartOfAccounts(): void
    {
        $this->artisan('db:seed', [
            '--class' => ChartOfAccountsSeeder::class,
            '--force' => true,
            '--no-interaction' => true,
        ]);
    }

    private function createRefundPay(float $amount): ReservationPay
    {
        $client = Client::query()->first();
        $stayReason = Stay_reason::query()->first();
        $source = Reservation_source::query()->first();
        $room = Room::query()->where('active', 1)->whereHas('roomType')->first();

        $this->assertNotNull($client);
        $this->assertNotNull($stayReason);
        $this->assertNotNull($source);
        $this->assertNotNull($room);

        $reservation = Reservation::query()->create([
            'client_id' => $client->id,
            'start_date' => '2026-08-10',
            'expire_date' => '2026-08-12',
            'nights' => 2,
            'reservation_type' => 0,
            'reservation_status' => Reservation::STATUS_CONFIRMED,
            'stay_reason_id' => $stayReason->id,
            'reservation_source_id' => $source->id,
            'rent_type' => 0,
            'base_price' => 500,
            'classification_discount' => 0,
            'discount' => 0,
            'extras' => 0,
            'penalties' => 0,
            'subtotal' => 500,
            'taxes' => 75,
            'total' => 575,
            'logedin' => Reservation::LOGEDIN_NOT_IN_HOUSE,
            'login_time' => '2026-08-10',
            'user_id' => $this->apiTestUser()->id,
        ]);

        ReservationRoom::query()->create([
            'reservation_id' => $reservation->id,
            'room_id' => $room->id,
            'suite_id' => null,
            'price' => 500,
        ]);

        return ReservationPay::query()->create([
            'reservation_id' => $reservation->id,
            'pay' => $amount,
            'type' => ReservationPay::TYPE_REFUND,
            'user_id' => $this->apiTestUser()->id,
        ]);
    }
}
