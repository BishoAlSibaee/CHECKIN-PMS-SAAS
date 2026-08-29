<?php

namespace Tests\Feature\Reservation;

use App\Models\Reservation;
use App\Models\ReservationPay;
use App\Services\CollectionsService;
use Carbon\Carbon;
use Database\Seeders\ReservationTestDataSeeder;
use Tests\TestCase;

class CollectionsTest extends TestCase
{
    private function seedAndUser(array $permissions = ['view payments', 'view reservations'])
    {
        $this->artisan('db:seed', [
            '--class' => ReservationTestDataSeeder::class,
            '--force' => true,
            '--no-interaction' => true,
        ]);

        return $this->userWithOnlyPermissions($permissions);
    }

    public function test_collections_summary_requires_view_payments_permission(): void
    {
        $this->seedAndUser(['view reservations']);

        $response = $this->actingAs($this->userWithOnlyPermissions(['view reservations']), 'api')
            ->getJson('/api/users/collections/summary');

        $response->assertForbidden();
    }

    public function test_collections_summary_matches_service(): void
    {
        $user = $this->seedAndUser();
        $today = Carbon::today();
        $expected = app(CollectionsService::class)->summarize($today);

        $response = $this->actingAs($user, 'api')->getJson('/api/users/collections/summary');
        $response->assertOk();
        $response->assertJsonPath('success', true);

        foreach (['total_balance', 'count', 'checkout_today_count', 'checkout_today_balance', 'in_house_count', 'overdue_count', 'overdue_balance'] as $key) {
            $this->assertEqualsWithDelta(
                (float) ($expected[$key] ?? 0),
                (float) $response->json("data.{$key}"),
                0.02,
                "Mismatch on collections summary key: {$key}"
            );
        }
    }

    public function test_collections_list_returns_only_positive_balances(): void
    {
        $user = $this->seedAndUser();

        $response = $this->actingAs($user, 'api')->getJson('/api/users/collections?tab=all');
        $response->assertOk();
        $response->assertJsonPath('success', true);

        $items = $response->json('data.items') ?? [];
        foreach ($items as $item) {
            $this->assertGreaterThan(0, (float) ($item['balance_due'] ?? 0));
            $this->assertArrayHasKey('urgency', $item);
            $this->assertArrayHasKey('guest', $item);
        }
    }

    public function test_collections_checkout_today_tab_filters_by_expire_date(): void
    {
        $user = $this->seedAndUser();
        $today = Carbon::today()->toDateString();

        $response = $this->actingAs($user, 'api')->getJson('/api/users/collections?tab=checkout_today');
        $response->assertOk();

        foreach ($response->json('data.items') ?? [] as $item) {
            $this->assertSame($today, $item['expire_date']);
            $this->assertGreaterThan(0, (float) $item['balance_due']);
        }
    }

    public function test_reservations_index_has_balance_due_filter(): void
    {
        $user = $this->seedAndUser();

        $filtered = $this->actingAs($user, 'api')->getJson('/api/users/reservations?has_balance_due=1');
        $filtered->assertOk();

        $rows = $filtered->json('data') ?? [];
        if ($rows === []) {
            $this->createPartiallyPaidReservation($user);
            $filtered = $this->actingAs($user, 'api')->getJson('/api/users/reservations?has_balance_due=1');
            $filtered->assertOk();
            $rows = $filtered->json('data') ?? [];
        }

        $this->assertNotEmpty($rows, 'Expected at least one reservation with balance due.');

        foreach ($rows as $row) {
            $reservation = Reservation::with('payments')->find($row['id']);
            $this->assertNotNull($reservation);

            $paid = (float) $reservation->payments
                ->where('type', ReservationPay::TYPE_PAYMENT)
                ->sum('pay');
            $refunded = (float) $reservation->payments
                ->where('type', ReservationPay::TYPE_REFUND)
                ->sum('pay');
            $balanceDue = max(0, (float) $reservation->total - ($paid - $refunded));

            $this->assertGreaterThan(0.005, $balanceDue);
        }
    }

    public function test_dashboard_summary_includes_collections(): void
    {
        $user = $this->seedAndUser(['view reservations', 'view revenue', 'view earnings', 'view payments']);
        $expected = app(CollectionsService::class)->summarize(Carbon::today());

        $response = $this->actingAs($user, 'api')->getJson('/api/users/dashboard/summary');
        $response->assertOk();

        $collections = $response->json('data.collections');
        $this->assertIsArray($collections);
        $this->assertEqualsWithDelta(
            (float) $expected['total_balance'],
            (float) ($collections['total_balance'] ?? 0),
            0.02
        );
        $this->assertSame((int) $expected['count'], (int) ($collections['count'] ?? 0));
    }

    public function test_future_reservation_with_logedin_flag_is_upcoming_not_in_house(): void
    {
        $this->seedAndUser();

        $client = \App\Models\Client::first();
        $stayReason = \App\Models\Stay_reason::first();
        $source = \App\Models\Reservation_source::first();
        $this->assertNotNull($client);
        $this->assertNotNull($stayReason);
        $this->assertNotNull($source);

        $futureStart = Carbon::today()->addMonths(2);
        $user = $this->apiTestUser();
        $reservation = Reservation::query()->create([
            'client_id' => $client->id,
            'start_date' => $futureStart->toDateString(),
            'expire_date' => $futureStart->copy()->addDays(4)->toDateString(),
            'nights' => 4,
            'reservation_type' => 0,
            'reservation_status' => Reservation::STATUS_CONFIRMED,
            'stay_reason_id' => $stayReason->id,
            'reservation_source_id' => $source->id,
            'rent_type' => 0,
            'base_price' => 869.57,
            'discount' => 0,
            'extras' => 0,
            'penalties' => 0,
            'subtotal' => 869.57,
            'taxes' => 130.43,
            'total' => 1000,
            'logedin' => Reservation::LOGEDIN_IN_HOUSE,
            'login_time' => now(),
            'user_id' => $user->id,
        ]);

        ReservationPay::query()->create([
            'reservation_id' => $reservation->id,
            'pay' => 200,
            'type' => ReservationPay::TYPE_PAYMENT,
            'user_id' => $user->id,
        ]);

        $service = app(CollectionsService::class);
        $today = Carbon::today();
        $list = $service->list($today, 'all', (string) $reservation->id);
        $item = $list['items']->first();

        $this->assertNotNull($item);
        $this->assertSame('upcoming', $item['urgency']);

        $inHouse = $service->list($today, 'in_house', (string) $reservation->id);
        $this->assertCount(0, $inHouse['items']);

        $upcoming = $service->list($today, 'upcoming', (string) $reservation->id);
        $this->assertCount(1, $upcoming['items']);
        $this->assertSame($reservation->id, $upcoming['items']->first()['reservation_id']);
    }

    private function createPartiallyPaidReservation(\App\Models\User $user): ?Reservation
    {
        $client = \App\Models\Client::first();
        $stayReason = \App\Models\Stay_reason::first();
        $source = \App\Models\Reservation_source::first();
        if (! $client || ! $stayReason || ! $source) {
            return null;
        }

        $start = Carbon::today()->addDays(2)->toDateString();
        $end = Carbon::today()->addDays(5)->toDateString();
        $room = $this->findOrCreateAvailableRoom($start, $end);
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
