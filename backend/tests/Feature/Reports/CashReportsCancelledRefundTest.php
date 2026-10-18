<?php

namespace Tests\Feature\Reports;

use App\Models\Client;
use App\Models\Reservation;
use App\Models\Reservation_source;
use App\Models\ReservationPay;
use App\Models\ReservationRoom;
use App\Models\Room;
use App\Models\Stay_reason;
use App\Services\Reports\ReportQueryService;
use Carbon\Carbon;
use Database\Seeders\ChartOfAccountsSeeder;
use Tests\TestCase;

class CashReportsCancelledRefundTest extends TestCase
{
    use \Illuminate\Foundation\Testing\DatabaseTransactions;

    protected function connectionsToTransact(): array
    {
        return ['mysql', 'mysql2'];
    }

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-08-20 12:00:00');
        $this->artisan('db:seed', [
            '--class' => ChartOfAccountsSeeder::class,
            '--force' => true,
            '--no-interaction' => true,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_refund_on_cancelled_reservation_appears_in_payments_refunds_report(): void
    {
        [$reservation, $refund] = $this->createCancelledReservationWithRefund(300.0);

        $report = app(ReportQueryService::class)->run('payments-refunds', [
            'start_date' => '2026-08-01',
            'end_date' => '2026-08-31',
        ]);

        $refundRow = collect($report['rows'])
            ->firstWhere('reservation_id', $reservation->id);

        $this->assertNotNull($refundRow, 'Refund on a cancelled reservation must appear in payments-refunds.');
        $this->assertSame('Refund', $refundRow['type']);
        $this->assertEqualsWithDelta(300.0, (float) $refundRow['amount'], 0.01);

        $summary = collect($report['summary'])->pluck('value', 'label');
        $this->assertEqualsWithDelta(300.0, (float) $summary->get('Cash out (refunds)', 0), 0.01);
    }

    public function test_refund_on_cancelled_reservation_counts_in_cash_box_cash_out(): void
    {
        $this->createCancelledReservationWithRefund(300.0);

        $report = app(ReportQueryService::class)->run('cash-box', [
            'start_date' => '2026-08-01',
            'end_date' => '2026-08-31',
        ]);

        $summary = collect($report['summary'])->pluck('value', 'label');
        $this->assertEqualsWithDelta(300.0, (float) $summary->get('Cash out (refunds)', 0), 0.01);
    }

    /**
     * @return array{0: Reservation, 1: ReservationPay}
     */
    private function createCancelledReservationWithRefund(float $refundAmount): array
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
            'reservation_status' => Reservation::STATUS_CANCELLED,
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

        $refund = ReservationPay::query()->create([
            'reservation_id' => $reservation->id,
            'pay' => $refundAmount,
            'type' => ReservationPay::TYPE_REFUND,
            'user_id' => $this->apiTestUser()->id,
        ]);

        return [$reservation, $refund];
    }
}
