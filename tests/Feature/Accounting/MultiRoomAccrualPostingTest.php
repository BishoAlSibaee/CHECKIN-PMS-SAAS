<?php

namespace Tests\Feature\Accounting;

use App\Models\ChartOfAccount;
use App\Models\Client;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\Reservation;
use App\Models\Reservation_source;
use App\Models\ReservationDailyCharge;
use App\Models\ReservationPay;
use App\Models\ReservationRoom;
use App\Models\Room;
use App\Models\Stay_reason;
use App\Services\Accounting\AccountingPostingService;
use App\Services\ReservationShortenService;
use App\Services\RevenueAccrualService;
use Carbon\Carbon;
use Tests\Support\FinanceTestBootstrap;
use Tests\TestCase;

class MultiRoomAccrualPostingTest extends TestCase
{
    use FinanceTestBootstrap;

    private const NIGHTLY_BASE = 320.0;

    private const NIGHTS = 3;

    private const CLASSIFICATION_DISCOUNT = 192.0;

    private const EXPECTED_NIGHTLY_REVENUE = 331.20;

    private const EXPECTED_TOTAL = 1987.20;

    protected function tearDown(): void
    {
        $this->resetFinanceTestNow();
        parent::tearDown();
    }

    public function test_multi_room_booking_accruals_equal_reservation_total_with_classification_discount(): void
    {
        $this->bootstrapFinanceData(false);

        $context = $this->createTwoRoomReservationContext('2028-11-10');
        $this->persistAndSyncAllRooms($context);

        $this->assertAccrualsBalancedForReservation($context['reservation']);

        $payment = ReservationPay::query()->create([
            'reservation_id' => $context['reservation']->id,
            'pay' => self::EXPECTED_TOTAL,
            'type' => ReservationPay::TYPE_PAYMENT,
            'user_id' => $this->apiTestUser()->id,
        ]);
        app(AccountingPostingService::class)->postPayment($payment);

        $this->assertEqualsWithDelta(0.0, $this->arNetForReservation($context['reservation']->id), 0.02);
    }

    public function test_sync_accrual_for_reservation_room_reposts_entire_reservation(): void
    {
        $this->bootstrapFinanceData(false);

        $context = $this->createTwoRoomReservationContext('2028-11-20');
        $this->persistAndSyncAllRooms($context);

        $this->deleteAccrualEntriesForReservation($context['reservation']->id);

        app(AccountingPostingService::class)->syncAccrualForReservationRoom($context['resRoomIds'][0]);

        $this->assertAccrualsBalancedForReservation($context['reservation']);
    }

    public function test_shorten_multi_room_reservation_keeps_ar_balanced(): void
    {
        Carbon::setTestNow('2028-12-02 12:00:00');
        $this->bootstrapFinanceData(false);

        $context = $this->createTwoRoomReservationContext('2028-12-01');
        $this->persistAndSyncAllRooms($context);

        $shortened = app(ReservationShortenService::class)->applyShorten(
            $context['reservation']->fresh(['reservationRooms']),
            Carbon::parse('2028-12-03')
        )->fresh();

        $posting = app(AccountingPostingService::class);
        $expected = 0.0;
        foreach (ReservationDailyCharge::where('reservation_id', $shortened->id)->get() as $charge) {
            $expected += $posting->allocateChargeAmounts($charge, $shortened)['revenue'];
        }

        $this->assertEqualsWithDelta($expected, $this->arAccrualTotalForReservation($shortened->id), 0.05);
    }

    public function test_allocate_charge_amounts_sum_matches_ar_debits(): void
    {
        $this->bootstrapFinanceData(false);

        $context = $this->createTwoRoomReservationContext('2028-12-15');
        $this->persistAndSyncAllRooms($context);

        $reservation = $context['reservation']->fresh();
        $posting = app(AccountingPostingService::class);
        $expected = 0.0;

        foreach (ReservationDailyCharge::where('reservation_id', $reservation->id)->get() as $charge) {
            $expected += $posting->allocateChargeAmounts($charge, $reservation)['revenue'];
        }

        $this->assertEqualsWithDelta($expected, $this->arAccrualTotalForReservation($reservation->id), 0.02);
    }

    /**
     * @return array{
     *     reservation: Reservation,
     *     roomIds: array<int, int>,
     *     resRoomIds: array<int, int>,
     *     lines: array<int, array<string, mixed>>
     * }
     */
    private function createTwoRoomReservationContext(string $startDate = '2028-11-10'): array
    {
        $client = Client::query()->first();
        $stayReason = Stay_reason::query()->first();
        $source = Reservation_source::query()->first();
        $rooms = Room::query()->where('active', 1)->whereHas('roomType')->limit(2)->get();

        $this->assertNotNull($client);
        $this->assertNotNull($stayReason);
        $this->assertNotNull($source);
        $this->assertGreaterThanOrEqual(2, $rooms->count());

        $roomIds = $rooms->pluck('id')->all();
        $subtotal = (self::NIGHTLY_BASE * self::NIGHTS * 2) - self::CLASSIFICATION_DISCOUNT;
        $expireDate = Carbon::parse($startDate)->addDays(self::NIGHTS)->toDateString();

        $reservation = Reservation::query()->create([
            'client_id' => $client->id,
            'start_date' => $startDate,
            'expire_date' => $expireDate,
            'nights' => self::NIGHTS,
            'reservation_type' => 0,
            'reservation_status' => Reservation::STATUS_CONFIRMED,
            'stay_reason_id' => $stayReason->id,
            'reservation_source_id' => $source->id,
            'rent_type' => 0,
            'base_price' => self::NIGHTLY_BASE * self::NIGHTS * 2,
            'classification_discount' => self::CLASSIFICATION_DISCOUNT,
            'discount' => 0,
            'extras' => 0,
            'penalties' => 0,
            'subtotal' => $subtotal,
            'taxes' => round($subtotal * 0.15, 2),
            'total' => self::EXPECTED_TOTAL,
            'logedin' => Reservation::LOGEDIN_NOT_IN_HOUSE,
            'login_time' => $startDate,
            'user_id' => $this->apiTestUser()->id,
        ]);

        $resRoomIds = [];
        foreach ($roomIds as $roomId) {
            $resRoomIds[] = ReservationRoom::query()->create([
                'reservation_id' => $reservation->id,
                'room_id' => $roomId,
                'suite_id' => null,
                'price' => self::EXPECTED_TOTAL / 2,
            ])->id;
        }

        return [
            'reservation' => $reservation,
            'roomIds' => $roomIds,
            'resRoomIds' => $resRoomIds,
            'lines' => $this->dailyLines($startDate, self::NIGHTS),
        ];
    }

    /**
     * @param  array{
     *     reservation: Reservation,
     *     roomIds: array<int, int>,
     *     resRoomIds: array<int, int>,
     *     lines: array<int, array<string, mixed>>
     * }  $context
     */
    private function persistAndSyncAllRooms(array $context): void
    {
        $revenueAccrual = app(RevenueAccrualService::class);

        foreach ($context['resRoomIds'] as $index => $resRoomId) {
            $revenueAccrual->persistDailyCharges(
                $context['reservation']->id,
                $resRoomId,
                $context['roomIds'][$index],
                0,
                $context['lines']
            );
        }

        app(AccountingPostingService::class)->syncAccrualForReservation($context['reservation']->id);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function dailyLines(string $start, int $nights): array
    {
        $lines = [];
        $date = Carbon::parse($start);

        for ($i = 0; $i < $nights; $i++) {
            $lines[] = [
                'date' => $date->copy()->addDays($i)->toDateString(),
                'base_amount' => self::NIGHTLY_BASE,
                'is_peak_day' => false,
                'is_in_plan' => false,
                'price_source' => 'test',
            ];
        }

        return $lines;
    }

    private function deleteAccrualEntriesForReservation(int $reservationId): void
    {
        $chargeIds = ReservationDailyCharge::where('reservation_id', $reservationId)->pluck('id');

        JournalEntry::query()
            ->where('source_type', 'daily_charge')
            ->whereIn('source_id', $chargeIds)
            ->each(function (JournalEntry $entry) {
                $entry->lines()->delete();
                $entry->delete();
            });
    }

    private function assertAccrualsBalancedForReservation(Reservation $reservation): void
    {
        $arAccountId = ChartOfAccount::where('code', '1100')->value('id');
        $this->assertNotNull($arAccountId);

        $debits = JournalEntryLine::query()
            ->join('journal_entries', 'journal_entry_lines.journal_entry_id', '=', 'journal_entries.id')
            ->where('journal_entry_lines.account_id', $arAccountId)
            ->where('journal_entry_lines.reservation_id', $reservation->id)
            ->where('journal_entries.source_type', 'daily_charge')
            ->pluck('journal_entry_lines.debit');

        $this->assertCount(self::NIGHTS * 2, $debits);
        foreach ($debits as $debit) {
            $this->assertEqualsWithDelta(self::EXPECTED_NIGHTLY_REVENUE, (float) $debit, 0.01);
        }

        $this->assertEqualsWithDelta(
            self::EXPECTED_TOTAL,
            $this->arAccrualTotalForReservation($reservation->id),
            0.02
        );
    }

    private function arAccrualTotalForReservation(int $reservationId): float
    {
        $arAccountId = ChartOfAccount::where('code', '1100')->value('id');

        return (float) JournalEntryLine::query()
            ->join('journal_entries', 'journal_entry_lines.journal_entry_id', '=', 'journal_entries.id')
            ->where('journal_entry_lines.account_id', $arAccountId)
            ->where('journal_entry_lines.reservation_id', $reservationId)
            ->where('journal_entries.source_type', 'daily_charge')
            ->sum('journal_entry_lines.debit');
    }

    private function arNetForReservation(int $reservationId): float
    {
        $arAccountId = ChartOfAccount::where('code', '1100')->value('id');

        return (float) JournalEntryLine::query()
            ->join('journal_entries', 'journal_entry_lines.journal_entry_id', '=', 'journal_entries.id')
            ->where('journal_entry_lines.account_id', $arAccountId)
            ->where('journal_entry_lines.reservation_id', $reservationId)
            ->selectRaw('COALESCE(SUM(journal_entry_lines.debit), 0) - COALESCE(SUM(journal_entry_lines.credit), 0) as net')
            ->value('net');
    }
}
