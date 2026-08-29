<?php

namespace Tests\Feature\Reports;

use App\Models\Reservation;
use App\Models\ReservationPay;
use App\Services\Reports\ReportQueryService;
use Carbon\Carbon;
use Database\Seeders\ReservationTestDataSeeder;
use Tests\TestCase;

class ArAgingReportTest extends TestCase
{
    private function seedAndUser(): \App\Models\User
    {
        $this->artisan('db:seed', [
            '--class' => ReservationTestDataSeeder::class,
            '--force' => true,
            '--no-interaction' => true,
        ]);

        return $this->userWithOnlyPermissions([
            'view reports', 'view financial reports', 'view payments', 'create reservations', 'update reservations',
        ]);
    }

    public function test_ar_aging_includes_pending_partial_payment_in_current_month(): void
    {
        $user = $this->seedAndUser();
        $reservation = $this->createPartiallyPaidReservation($user);
        $this->assertNotNull($reservation);
        $this->assertSame(Reservation::STATUS_PENDING_PAYMENT, (int) $reservation->reservation_status);

        $asOf = Carbon::today()->toDateString();
        $report = app(ReportQueryService::class)->run('ar-aging', [
            'end_date' => $asOf,
        ]);

        $rows = collect($report['rows'] ?? []);
        $match = $rows->firstWhere('reservation_id', $reservation->id);

        $this->assertNotNull($match, 'Pending partial-payment stay in this month must appear in ar-aging.');
        $this->assertGreaterThan(0, (float) ($match['balance_due'] ?? 0));
    }

    public function test_ar_aging_excludes_stays_outside_month_of_as_of_date(): void
    {
        $user = $this->seedAndUser();
        $reservation = $this->createPartiallyPaidReservation($user, monthsAhead: 3);
        $this->assertNotNull($reservation);

        $report = app(ReportQueryService::class)->run('ar-aging', [
            'end_date' => Carbon::today()->endOfMonth()->toDateString(),
        ]);

        $this->assertNull(
            collect($report['rows'] ?? [])->firstWhere('reservation_id', $reservation->id),
            'Future-month stay must not appear when as-of month is the current month.'
        );
    }

    public function test_ar_aging_balance_uses_payments_on_or_before_as_of_date(): void
    {
        $user = $this->seedAndUser();
        $reservation = $this->createPartiallyPaidReservation($user, monthsAhead: 0, startDayOffset: 5);
        $this->assertNotNull($reservation);

        $stayMonthEnd = Carbon::parse($reservation->start_date)->endOfMonth()->toDateString();
        $partialPay = $reservation->payments()->where('type', ReservationPay::TYPE_PAYMENT)->first();
        $this->assertNotNull($partialPay);
        $partialPay->created_at = Carbon::parse($reservation->start_date)->addDay()->setTime(12, 0);
        $partialPay->save();

        $beforeFullPay = app(ReportQueryService::class)->run('ar-aging', ['end_date' => $stayMonthEnd]);
        $beforeBalance = (float) (collect($beforeFullPay['rows'] ?? [])
            ->firstWhere('reservation_id', $reservation->id)['balance_due'] ?? 0);
        $this->assertGreaterThan(0, $beforeBalance);

        $remaining = $reservation->fresh('payments')->balanceDue();
        $this->actingAs($user, 'api')->postJson(
            "/api/users/reservations/{$reservation->id}/payments",
            ['pay' => $remaining, 'type' => ReservationPay::TYPE_PAYMENT]
        )->assertOk();

        $finalPay = $reservation->payments()->where('type', ReservationPay::TYPE_PAYMENT)->orderByDesc('id')->first();
        $finalPay->created_at = Carbon::parse($stayMonthEnd)->addDay()->setTime(12, 0);
        $finalPay->save();

        $historical = app(ReportQueryService::class)->run('ar-aging', ['end_date' => $stayMonthEnd]);
        $historicalBalance = (float) (collect($historical['rows'] ?? [])
            ->firstWhere('reservation_id', $reservation->id)['balance_due'] ?? 0);

        $this->assertGreaterThan(0, $historicalBalance, 'As-of month-end must ignore payments recorded after that date.');
        $this->assertEqualsWithDelta($beforeBalance, $historicalBalance, 0.02);
    }

    public function test_fully_paid_reservation_excluded_from_ar_aging_as_of_today(): void
    {
        $user = $this->seedAndUser();
        $reservation = $this->createPartiallyPaidReservation($user);
        $this->assertNotNull($reservation);

        $balance = $reservation->fresh('payments')->balanceDue();
        $this->actingAs($user, 'api')->postJson(
            "/api/users/reservations/{$reservation->id}/payments",
            ['pay' => $balance, 'type' => ReservationPay::TYPE_PAYMENT]
        )->assertOk();

        $report = app(ReportQueryService::class)->run('ar-aging', [
            'end_date' => Carbon::today()->toDateString(),
        ]);

        $this->assertNull(collect($report['rows'] ?? [])->firstWhere('reservation_id', $reservation->id));
    }

    private function createPartiallyPaidReservation(
        \App\Models\User $user,
        int $monthsAhead = 0,
        int $startDayOffset = 2,
    ): ?Reservation {
        $client = \App\Models\Client::first();
        $stayReason = \App\Models\Stay_reason::first();
        $source = \App\Models\Reservation_source::first();
        $room = \App\Models\Room::query()->first();
        if (! $client || ! $stayReason || ! $source || ! $room) {
            return null;
        }

        $start = Carbon::today()->addMonths($monthsAhead)->addDays($startDayOffset)->toDateString();
        $end = Carbon::parse($start)->addDays(3)->toDateString();

        $response = $this->actingAs($user, 'api')->postJson('/api/users/makeReservation', [
            'client_id' => $client->id,
            'rooms' => [['room_id' => $room->id]],
            'start_date' => $start,
            'expire_date' => $end,
            'reservation_type' => 0,
            'stay_reason_id' => $stayReason->id,
            'reservation_source_id' => $source->id,
            'rent_type' => 0,
            'price_calculation_mode' => 0,
            'discount' => 0,
            'extras' => 0,
            'penalties' => 0,
            'pay_amount' => 0,
        ]);

        if ($response->status() !== 200 || ! $response->json('success')) {
            return null;
        }

        $id = $response->json('data.id') ?? $response->json('data.reservation.id') ?? null;
        $reservation = $id ? Reservation::find($id) : Reservation::latest('id')->first();
        if (! $reservation) {
            return null;
        }

        $partial = max(1, round((float) $reservation->total * 0.4, 2));
        $this->actingAs($user, 'api')->postJson(
            "/api/users/reservations/{$reservation->id}/payments",
            ['pay' => $partial, 'type' => ReservationPay::TYPE_PAYMENT]
        );

        return $reservation->fresh('payments');
    }
}
