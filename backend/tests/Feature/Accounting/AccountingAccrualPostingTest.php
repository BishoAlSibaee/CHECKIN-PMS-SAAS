<?php

namespace Tests\Feature\Accounting;

use App\Models\Client;
use App\Models\Reservation;
use App\Models\Reservation_source;
use App\Models\ReservationDailyCharge;
use App\Models\ReservationRoom;
use App\Models\Room;
use App\Models\Stay_reason;
use App\Services\Accounting\AccountingPostingService;
use App\Services\Accounting\FinancialStatementService;
use App\Services\RevenueAccrualService;
use Carbon\Carbon;
use Tests\Support\FinanceTestBootstrap;
use Tests\TestCase;

class AccountingAccrualPostingTest extends TestCase
{
    use FinanceTestBootstrap;

    public function test_accrual_gl_matches_revenue_accrual_service_for_august_2026(): void
    {
        $this->bootstrapFinanceData();

        $start = Carbon::parse(self::FINANCE_PERIOD_START);
        $end = Carbon::parse(self::FINANCE_PERIOD_END);

        $accrual = app(RevenueAccrualService::class)->calculate('total', null, $start, $end, false);
        $expectedSubtotal = round((float) $accrual['current']['subtotal'], 2);
        $expectedTax = round((float) $accrual['current']['tax'], 2);
        $expectedRevenue = round((float) $accrual['current']['total'], 2);

        $balances = app(FinancialStatementService::class)->accountBalances($start, $end);
        $byCode = $balances->keyBy('code');

        $roomRevenue = round((float) ($byCode->get('4010')->balance ?? 0), 2);
        $vat = round((float) ($byCode->get('2150')->balance ?? 0), 2);
        $ar = round((float) ($byCode->get('1100')->balance ?? 0), 2);

        $this->assertEqualsWithDelta($expectedSubtotal, $roomRevenue, 0.15, 'GL room revenue vs accrual subtotal');
        $this->assertEqualsWithDelta($expectedTax, $vat, 0.15, 'GL VAT vs accrual tax');

        $trial = app(FinancialStatementService::class)->trialBalance($start, $end);
        $this->assertTrue($trial['totals']['balanced'], 'Trial balance must balance for August 2026');

        $cashNet = round((float) ($byCode->get('1010')->balance ?? 0), 2);
        $this->assertGreaterThan(0, $expectedRevenue, 'Fixture should have August accrual');
        $this->assertNotEquals($ar, $expectedRevenue, 'AR net includes payments; should differ from gross accrual when cash collected');
        $this->assertIsFloat($cashNet);
    }

    public function test_post_daily_charge_accrual_is_idempotent(): void
    {
        $this->bootstrapFinanceData();

        $charge = \App\Models\ReservationDailyCharge::query()
            ->join('reservations', 'reservation_daily_charges.reservation_id', '=', 'reservations.id')
            ->where('reservations.reservation_status', 1)
            ->select('reservation_daily_charges.*')
            ->first();

        $this->assertNotNull($charge);

        $posting = app(AccountingPostingService::class);
        $first = $posting->postDailyChargeAccrual($charge);
        $second = $posting->postDailyChargeAccrual($charge);

        $this->assertNotNull($first);
        $this->assertSame($first->id, $second->id);
    }

    public function test_daily_charge_accrual_allocates_classification_and_manual_discounts(): void
    {
        $this->bootstrapFinanceData();

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
            'start_date' => '2027-02-01',
            'expire_date' => '2027-02-02',
            'nights' => 1,
            'reservation_type' => 0,
            'reservation_status' => Reservation::STATUS_CONFIRMED,
            'stay_reason_id' => $stayReason->id,
            'reservation_source_id' => $source->id,
            'rent_type' => 0,
            'base_price' => 1000,
            'classification_discount' => 100,
            'discount' => 50,
            'extras' => 0,
            'penalties' => 0,
            'subtotal' => 850,
            'taxes' => 127.5,
            'total' => 977.5,
            'logedin' => Reservation::LOGEDIN_NOT_IN_HOUSE,
            'login_time' => '2027-02-01',
            'user_id' => $this->apiTestUser()->id,
        ]);

        $reservationRoom = ReservationRoom::query()->create([
            'reservation_id' => $reservation->id,
            'room_id' => $room->id,
            'suite_id' => null,
            'price' => 1000,
        ]);

        $charge = ReservationDailyCharge::query()->create([
            'reservation_id' => $reservation->id,
            'reservation_room_id' => $reservationRoom->id,
            'room_id' => $room->id,
            'charge_date' => '2027-02-01',
            'base_amount' => 1000,
            'is_peak_day' => false,
            'is_in_plan' => false,
            'price_source' => 'test',
            'rent_type' => 0,
        ]);

        $amounts = app(AccountingPostingService::class)->allocateChargeAmounts($charge, $reservation);

        $this->assertEqualsWithDelta(850.0, $amounts['subtotal'], 0.01);
        $this->assertEqualsWithDelta(127.5, $amounts['tax'], 0.01);
        $this->assertEqualsWithDelta(977.5, $amounts['revenue'], 0.01);
    }

    public function test_accrual_journal_reference_uses_room_number(): void
    {
        $this->bootstrapFinanceData();

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
            'start_date' => '2027-03-01',
            'expire_date' => '2027-03-02',
            'nights' => 1,
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
            'login_time' => '2027-03-01',
            'user_id' => $this->apiTestUser()->id,
        ]);

        $reservationRoom = ReservationRoom::query()->create([
            'reservation_id' => $reservation->id,
            'room_id' => $room->id,
            'suite_id' => null,
            'price' => 500,
        ]);

        $charge = ReservationDailyCharge::query()->create([
            'reservation_id' => $reservation->id,
            'reservation_room_id' => $reservationRoom->id,
            'room_id' => $room->id,
            'charge_date' => '2027-03-01',
            'base_amount' => 500,
            'is_peak_day' => false,
            'is_in_plan' => false,
            'price_source' => 'test',
            'rent_type' => 0,
        ]);

        $posting = app(AccountingPostingService::class);
        $entry = $posting->postDailyChargeAccrual($charge);

        $this->assertNotNull($entry);
        $this->assertStringStartsWith(
            'ACCRUAL-'.$room->number.'-2027-03-01',
            $entry->reference
        );
    }

    public function test_humanize_accrual_reference_replaces_legacy_reservation_room_id(): void
    {
        $humanized = AccountingPostingService::humanizeAccrualReference(
            'ACCRUAL-RR17650-2026-07-13',
            [17650 => '103']
        );

        $this->assertSame('ACCRUAL-103-2026-07-13', $humanized);
    }

    public function test_accrual_reference_search_terms_expand_humanized_room_number(): void
    {
        $this->bootstrapFinanceData();

        $room = Room::query()->where('active', 1)->whereHas('roomType')->first();
        $reservation = Reservation::query()->first();
        $this->assertNotNull($room);
        $this->assertNotNull($reservation);

        $room->forceFill(['number' => '99001'])->save();

        $reservationRoom = ReservationRoom::query()->create([
            'reservation_id' => $reservation->id,
            'room_id' => $room->id,
            'suite_id' => null,
            'price' => 100,
        ]);

        $terms = AccountingPostingService::accrualReferenceSearchTerms('ACCRUAL-99001-2026-07-04');

        $this->assertContains('ACCRUAL-99001-2026-07-04', $terms);
        $this->assertContains('ACCRUAL-RR'.$reservationRoom->id.'-2026-07-04', $terms);
        $this->assertSame(['PAY-123'], AccountingPostingService::accrualReferenceSearchTerms('PAY-123'));
    }
}
