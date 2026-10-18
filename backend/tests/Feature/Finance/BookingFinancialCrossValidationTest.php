<?php

namespace Tests\Feature\Finance;

use App\Models\Client;
use App\Models\Reservation;
use App\Models\Reservation_source;
use App\Models\ReservationDailyCharge;
use App\Models\ReservationPay;
use App\Models\Stay_reason;
use App\Services\RevenueAccrualService;
use Carbon\Carbon;
use Database\Seeders\ReservationTestDataSeeder;
use Tests\Support\FinanceAssertions;
use Tests\TestCase;

/**
 * End-to-end: create booking → verify ledger math → pay → cross-check dashboard vs reports.
 */
class BookingFinancialCrossValidationTest extends TestCase
{
    use \Illuminate\Foundation\Testing\DatabaseTransactions;

    protected function connectionsToTransact(): array
    {
        return ['mysql', 'mysql2'];
    }

    use FinanceAssertions;

    private const PERIOD_START = '2026-08-01';

    private const PERIOD_END = '2026-08-31';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-08-15');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function seedAndUser(): \App\Models\User
    {
        $this->artisan('db:seed', [
            '--class' => ReservationTestDataSeeder::class,
            '--force' => true,
            '--no-interaction' => true,
        ]);

        return $this->userWithApiPermissions([
            'view reservations', 'create reservations', 'view revenue', 'view earnings',
            'view reports', 'view financial reports', 'view accounting reports',
        ]);
    }

    public function test_new_booking_and_payment_match_dashboard_reports_and_reservation_totals(): void
    {
        $user = $this->seedAndUser();
        $accrualSvc = app(RevenueAccrualService::class);

        $accrualBefore = $accrualSvc->calculate('total', null, Carbon::parse(self::PERIOD_START), Carbon::parse(self::PERIOD_END), false);
        $earnBefore = $this->jsonGet($user, '/api/users/earnings-summary?start_date='.self::PERIOD_START.'&end_date='.self::PERIOD_END);
        $dashBefore = $this->jsonGet($user, '/api/users/financials/dashboard?start_date='.self::PERIOD_START.'&end_date='.self::PERIOD_END);

        $reservation = $this->createFutureReservation($user);
        $this->assertNotNull($reservation, 'Could not create a reservation for cross-validation.');

        $reservation->refresh();
        $this->assertReservationFinancialContract($reservation, 'new booking');

        $show = $this->actingAs($user, 'api')->getJson("/api/users/reservations/{$reservation->id}");
        $show->assertOk();
        $show->assertJsonPath('success', true);
        $payload = $show->json('data.reservation') ?? $show->json('data');
        $this->assertEqualsWithDelta((float) $reservation->total, (float) ($payload['total'] ?? 0), 0.02);

        $chargeSum = (float) ReservationDailyCharge::where('reservation_id', $reservation->id)->sum('base_amount');
        $this->assertEqualsWithDelta((float) $reservation->base_price, $chargeSum, 0.02);

        $accrualAfterBooking = $accrualSvc->calculate('total', null, Carbon::parse(self::PERIOD_START), Carbon::parse(self::PERIOD_END), false);
        $reservationAccrualInPeriod = (float) ReservationDailyCharge::query()
            ->where('reservation_id', $reservation->id)
            ->whereBetween('charge_date', [self::PERIOD_START, self::PERIOD_END])
            ->sum('base_amount');
        $expectedAccrualDelta = round($reservationAccrualInPeriod * 1.15, 2);

        $accrualDelta = round(
            (float) ($accrualAfterBooking['current']['total'] ?? 0) - (float) ($accrualBefore['current']['total'] ?? 0),
            2
        );
        $this->assertEqualsWithDelta(
            $expectedAccrualDelta,
            $accrualDelta,
            0.10,
            'Accrual service delta should match new reservation charges in period (incl. tax)'
        );

        $overview = $this->jsonGet($user, '/api/users/reports/overview?start_date='.self::PERIOD_START.'&end_date='.self::PERIOD_END);
        $overviewRevenue = $this->reportSummaryValue($overview['data'] ?? [], 'Accrual revenue');
        $this->assertEqualsWithDelta(
            (float) ($accrualAfterBooking['current']['total'] ?? 0),
            $overviewRevenue ?? 0,
            0.05,
            'Overview report accrual must match accrual service'
        );

        $accrualReport = $this->jsonGet($user, '/api/users/reports/accrual-revenue?start_date='.self::PERIOD_START.'&end_date='.self::PERIOD_END);
        $reportRevenue = $this->reportSummaryValue($accrualReport['data'] ?? [], 'Total revenue');
        $this->assertEqualsWithDelta(
            (float) ($accrualAfterBooking['current']['total'] ?? 0),
            $reportRevenue ?? 0,
            0.05,
            'Accrual-revenue report must match accrual service'
        );

        $paid = (float) $reservation->payments()->where('type', ReservationPay::TYPE_PAYMENT)->sum('pay');
        $refunded = (float) $reservation->payments()->where('type', ReservationPay::TYPE_REFUND)->sum('pay');
        $balanceDue = max(0, round((float) $reservation->total - ($paid - $refunded), 2));
        $this->assertGreaterThan(0, $balanceDue);

        $payAmount = round($balanceDue, 2);
        $payResponse = $this->actingAs($user, 'api')->postJson(
            "/api/users/reservations/{$reservation->id}/payments",
            ['pay' => $payAmount, 'type' => ReservationPay::TYPE_PAYMENT]
        );
        $payResponse->assertOk();
        $payResponse->assertJsonPath('success', true);

        $earnAfter = $this->jsonGet($user, '/api/users/earnings-summary?start_date='.self::PERIOD_START.'&end_date='.self::PERIOD_END);
        $cashInBefore = (float) ($earnBefore['data']['total_in'] ?? 0);
        $cashInAfter = (float) ($earnAfter['data']['total_in'] ?? 0);
        $this->assertEqualsWithDelta($cashInBefore + $payAmount, $cashInAfter, 0.02);

        $dashAfter = $this->jsonGet($user, '/api/users/financials/dashboard?start_date='.self::PERIOD_START.'&end_date='.self::PERIOD_END);
        $kpis = $dashAfter['data']['kpis'] ?? [];
        $this->assertEqualsWithDelta((float) ($accrualAfterBooking['current']['total'] ?? 0), (float) ($kpis['accrual_total'] ?? 0), 0.05);
        $this->assertEqualsWithDelta($cashInAfter, (float) ($kpis['cash_in'] ?? 0), 0.05);

        $revenueEndpoint = $this->jsonGet($user, '/api/users/revenue/total?start_date='.self::PERIOD_START.'&end_date='.self::PERIOD_END.'&include_details=0');
        $revTotal = (float) ($revenueEndpoint['data']['revenue']['current']['total'] ?? $revenueEndpoint['data']['current']['total'] ?? 0);
        $this->assertEqualsWithDelta((float) ($kpis['accrual_total'] ?? 0), $revTotal, 0.05);

        $cashBox = $this->jsonGet($user, '/api/users/reports/cash-box?start_date='.self::PERIOD_START.'&end_date='.self::PERIOD_END);
        $cashNet = $this->reportSummaryValue($cashBox['data'] ?? [], 'Net');
        $this->assertEqualsWithDelta((float) ($earnAfter['data']['net_earnings'] ?? 0), $cashNet ?? 0, 0.10);

        $recon = $this->jsonGet($user, '/api/users/reports/accrual-cash-reconciliation?start_date='.self::PERIOD_START.'&end_date='.self::PERIOD_END);
        $reconAccrual = $this->reportSummaryValue($recon['data'] ?? [], 'Accrual revenue');
        $reconCash = $this->reportSummaryValue($recon['data'] ?? [], 'Cash net');
        $this->assertEqualsWithDelta((float) ($accrualAfterBooking['current']['total'] ?? 0), $reconAccrual ?? 0, 0.05);
        $this->assertEqualsWithDelta((float) ($earnAfter['data']['net_earnings'] ?? 0), $reconCash ?? 0, 0.10);

        $showAfterPay = $this->actingAs($user, 'api')->getJson("/api/users/reservations/{$reservation->id}");
        $showAfterPay->assertOk();
        $paidNet = (float) ($showAfterPay->json('data.reservation.paid_amount') ?? $showAfterPay->json('data.paid_amount') ?? 0);
        $this->assertEqualsWithDelta($payAmount, $paidNet, 0.02);
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonGet(\App\Models\User $user, string $path): array
    {
        $response = $this->actingAs($user, 'api')->getJson($path);
        $response->assertOk();
        $response->assertJsonPath('success', true);

        return $response->json();
    }

    private function createFutureReservation(\App\Models\User $user): ?Reservation
    {
        $client = Client::first();
        $stayReason = Stay_reason::first();
        $source = Reservation_source::first();
        if (! $client || ! $stayReason || ! $source) {
            return null;
        }

        $start = '2026-08-15';
        $end = '2026-08-16';

        $room = $this->createIsolatedTestRoom() ?? $this->findOrCreateAvailableRoom($start, $end);
        if (! $room) {
            return null;
        }

        $response = $this->actingAs($user, 'api')->postJson('/api/users/makeReservation', [
            'client_id' => $client->id,
            'rooms' => [['room_id' => $room->id]],
            'start_date' => $start,
            'expire_date' => $end,
            'reservation_type' => 0,
            'reservation_status' => 1,
            'stay_reason_id' => $stayReason->id,
            'reservation_source_id' => $source->id,
            'rent_type' => 0,
            'price_calculation_mode' => 0,
            'discount' => 0,
            'extras' => 0,
            'penalties' => 0,
            'pay_amount' => 0,
            'logedin' => 0,
        ]);

        if ($response->status() !== 200 || ! $response->json('success')) {
            return null;
        }

        $id = $response->json('data.id') ?? $response->json('data.reservation.id') ?? null;

        return $id ? Reservation::with(['payments', 'reservationRooms.room'])->find($id) : null;
    }
}
