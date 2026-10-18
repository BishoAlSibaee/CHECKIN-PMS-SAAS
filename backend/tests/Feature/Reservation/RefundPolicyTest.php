<?php

namespace Tests\Feature\Reservation;

use App\Models\Client;
use App\Models\RefundPolicy;
use App\Models\Reservation;
use App\Models\Reservation_source;
use App\Models\ReservationPay;
use App\Models\Stay_reason;
use Carbon\Carbon;
use Database\Seeders\RefundPolicySeeder;
use Database\Seeders\ReservationTestDataSeeder;
use Tests\TestCase;

class RefundPolicyTest extends TestCase
{
    use \Illuminate\Foundation\Testing\DatabaseTransactions;

    protected function connectionsToTransact(): array
    {
        return ['mysql', 'mysql2'];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('db:seed', [
            '--class' => RefundPolicySeeder::class,
            '--force' => true,
            '--no-interaction' => true,
        ]);
    }

    public function test_preview_rejects_completed_stay(): void
    {
        Carbon::setTestNow('2026-09-01');

        $user = $this->userWithApiPermissions(['manage refunds']);
        $reservation = $this->createPaidReservation('2026-09-10', '2026-09-15', $user);
        $this->assertNotNull($reservation);

        $reservation->update(['expire_date' => '2026-08-31', 'start_date' => '2026-08-20']);

        $response = $this->actingAs($user, 'api')->getJson(
            '/api/users/refund-policies/preview?reservation_id='.$reservation->id
        );

        $response->assertStatus(422);
        $response->assertJsonPath('success', false);

        Carbon::setTestNow();
    }

    public function test_preview_and_refund_amount_match(): void
    {
        Carbon::setTestNow('2026-06-01');

        $user = $this->userWithApiPermissions(['manage refunds']);
        $reservation = $this->createPaidReservation('2026-06-15', '2026-06-20', $user);
        $this->assertNotNull($reservation);

        $preview = $this->actingAs($user, 'api')->getJson(
            '/api/users/refund-policies/preview?reservation_id='.$reservation->id
        );
        $preview->assertStatus(200);
        $preview->assertJsonPath('success', true);

        $amount = (float) $preview->json('data.refund_amount');
        $this->assertGreaterThan(0, $amount);

        $refund = $this->actingAs($user, 'api')->postJson('/api/users/refund', [
            'reservation_id' => $reservation->id,
        ]);
        $refund->assertStatus(200);
        $refund->assertJsonPath('success', true);
        $this->assertEqualsWithDelta($amount, (float) $refund->json('data.amount'), 0.01);

        $reservation->refresh();
        $this->assertEquals(Reservation::STATUS_CANCELLED, (int) $reservation->reservation_status);

        Carbon::setTestNow();
    }

    public function test_refund_appears_in_earnings_after_cancelled(): void
    {
        Carbon::setTestNow('2026-06-01');

        $user = $this->userWithApiPermissions(['manage refunds']);
        $reservation = $this->createPaidReservation('2026-06-15', '2026-06-20', $user);
        $this->assertNotNull($reservation);

        $refund = $this->actingAs($user, 'api')->postJson('/api/users/refund', [
            'reservation_id' => $reservation->id,
        ]);
        $refund->assertStatus(200);
        $amount = (float) $refund->json('data.amount');

        $summary = $this->actingAs($user, 'api')->getJson(
            '/api/users/earnings-summary?start_date=2026-06-01&end_date=2026-06-30'
        );
        $summary->assertStatus(200);
        $this->assertGreaterThanOrEqual($amount, (float) $summary->json('data.total_out'));

        Carbon::setTestNow();
    }

    public function test_percent_threshold_before_start_requires_resolved_days(): void
    {
        Carbon::setTestNow('2026-06-11');

        RefundPolicy::query()->where('timing', 'before_start')->delete();
        RefundPolicy::create([
            'name' => 'Percent before start — 50% of stay',
            'rent_type' => 0,
            'timing' => 'before_start',
            'threshold_mode' => RefundPolicy::THRESHOLD_PERCENT_OF_STAY,
            'threshold_percent' => 50,
            'days_threshold' => 0,
            'days_before_checkin' => 0,
            'refund_percent' => 50,
            'refund_basis' => 'remaining_nights',
            'payment_status' => 2,
            'during_stay' => 0,
            'payment_statuses' => ['paid'],
        ]);

        $user = $this->userWithApiPermissions(['manage refunds']);
        $reservation = $this->createPaidReservation('2026-06-15', '2026-06-23', $user);
        $this->assertNotNull($reservation);

        $preview = $this->actingAs($user, 'api')->getJson(
            '/api/users/refund-policies/preview?reservation_id='.$reservation->id
        );
        $preview->assertStatus(200);
        $preview->assertJsonPath('success', true);
        $this->assertEquals(4, (int) $preview->json('data.breakdown.resolved_threshold_days'));

        Carbon::setTestNow('2026-06-12');
        $tooLate = $this->actingAs($user, 'api')->getJson(
            '/api/users/refund-policies/preview?reservation_id='.$reservation->id
        );
        $tooLate->assertStatus(422);

        Carbon::setTestNow();
    }

    public function test_priority_breaks_threshold_tie_between_percent_and_fixed(): void
    {
        RefundPolicy::query()->delete();

        RefundPolicy::create([
            'name' => 'After one day',
            'rent_type' => null,
            'timing' => 'after_start',
            'threshold_mode' => RefundPolicy::THRESHOLD_FIXED_DAYS,
            'days_threshold' => 1,
            'days_before_checkin' => 1,
            'refund_percent' => 30,
            'refund_basis' => 'paid_net',
            'during_stay' => 1,
            'priority' => 0,
            'payment_statuses' => ['paid'],
        ]);

        RefundPolicy::create([
            'name' => 'Half of stay',
            'rent_type' => null,
            'timing' => 'after_start',
            'threshold_mode' => RefundPolicy::THRESHOLD_PERCENT_OF_STAY,
            'threshold_percent' => 50,
            'days_threshold' => 0,
            'days_before_checkin' => 0,
            'refund_percent' => 40,
            'refund_basis' => 'paid_net',
            'during_stay' => 1,
            'priority' => 0,
            'payment_statuses' => ['paid'],
        ]);

        Carbon::setTestNow('2026-07-06');

        $user = $this->userWithApiPermissions(['manage refunds']);
        $reservation = $this->createPaidReservation('2026-07-06', '2026-07-08', $user);
        $this->assertNotNull($reservation);

        $service = app(\App\Services\RefundPolicyService::class);

        Carbon::setTestNow('2026-07-07');
        $this->assertSame('After one day', $service->resolvePolicy($reservation->fresh(['payments']))?->name);

        RefundPolicy::query()->where('name', 'Half of stay')->update(['priority' => 1]);
        $this->assertSame('Half of stay', $service->resolvePolicy($reservation->fresh(['payments']))?->name);

        Carbon::setTestNow();
    }

    public function test_refund_policy_crud_accepts_priority_for_percent_policy(): void
    {
        $user = $this->userWithApiPermissions(['manage refund policies']);

        $create = $this->actingAs($user, 'api')->postJson('/api/users/refund-policies', [
            'name' => 'Percent with tie priority',
            'rent_type' => 0,
            'timing' => 'after_start',
            'threshold_mode' => 'percent_of_stay',
            'threshold_percent' => 50,
            'refund_percent' => 40,
            'refund_basis' => 'paid_net',
            'payment_statuses' => ['paid'],
            'priority' => 1,
        ]);
        $create->assertStatus(200);
        $create->assertJsonPath('data.priority', 1);

        $id = $create->json('data.id');
        $this->actingAs($user, 'api')->putJson("/api/users/refund-policies/{$id}", [
            'priority' => 0,
        ])->assertStatus(200)->assertJsonPath('data.priority', 0);

        $this->actingAs($user, 'api')->deleteJson('/api/users/refund-policies', ['id' => $id])
            ->assertStatus(200);
    }

    public function test_highest_matching_threshold_policy_wins(): void
    {
        RefundPolicy::query()->delete();

        RefundPolicy::create([
            'name' => 'Base tier',
            'rent_type' => null,
            'timing' => 'after_start',
            'threshold_mode' => RefundPolicy::THRESHOLD_FIXED_DAYS,
            'days_threshold' => 0,
            'days_before_checkin' => 0,
            'refund_percent' => 50,
            'refund_basis' => 'total',
            'during_stay' => 1,
            'payment_statuses' => ['paid'],
        ]);

        RefundPolicy::create([
            'name' => 'Higher tier',
            'rent_type' => null,
            'timing' => 'after_start',
            'threshold_mode' => RefundPolicy::THRESHOLD_PERCENT_OF_STAY,
            'threshold_percent' => 50,
            'days_threshold' => 0,
            'days_before_checkin' => 0,
            'refund_percent' => 40,
            'refund_basis' => 'paid_net',
            'during_stay' => 1,
            'payment_statuses' => ['paid'],
        ]);

        Carbon::setTestNow('2026-07-06');

        $user = $this->userWithApiPermissions(['manage refunds']);
        $reservation = $this->createPaidReservation('2026-07-06', '2026-07-13', $user);
        $this->assertNotNull($reservation);

        $service = app(\App\Services\RefundPolicyService::class);

        Carbon::setTestNow('2026-07-07');
        $this->assertSame('Base tier', $service->resolvePolicy($reservation->fresh(['payments']))?->name);

        Carbon::setTestNow('2026-07-10');
        $this->assertSame('Higher tier', $service->resolvePolicy($reservation->fresh(['payments']))?->name);

        Carbon::setTestNow();
    }

    public function test_same_day_policy_does_not_apply_after_half_stay_threshold(): void
    {
        RefundPolicy::query()->delete();

        RefundPolicy::create([
            'name' => 'Same day of stay',
            'rent_type' => null,
            'timing' => 'after_start',
            'threshold_mode' => RefundPolicy::THRESHOLD_FIXED_DAYS,
            'days_threshold' => 0,
            'days_before_checkin' => 0,
            'refund_percent' => 50,
            'refund_basis' => 'total',
            'payment_status' => 2,
            'during_stay' => 1,
            'payment_statuses' => ['paid'],
        ]);

        RefundPolicy::create([
            'name' => 'After half of stay',
            'rent_type' => null,
            'timing' => 'after_start',
            'threshold_mode' => RefundPolicy::THRESHOLD_PERCENT_OF_STAY,
            'threshold_percent' => 50,
            'days_threshold' => 0,
            'days_before_checkin' => 0,
            'refund_percent' => 40,
            'refund_basis' => 'paid_net',
            'payment_status' => 2,
            'during_stay' => 1,
            'payment_statuses' => ['paid'],
        ]);

        Carbon::setTestNow('2026-07-06');

        $user = $this->userWithApiPermissions(['manage refunds']);
        $reservation = $this->createPaidReservation('2026-07-06', '2026-07-13', $user);
        $this->assertNotNull($reservation);

        $service = app(\App\Services\RefundPolicyService::class);
        $sameDay = $service->resolvePolicy($reservation->fresh(['payments']));
        $this->assertSame('Same day of stay', $sameDay?->name);

        Carbon::setTestNow('2026-07-07');
        $dayOne = $service->resolvePolicy($reservation->fresh(['payments']));
        $this->assertSame('Same day of stay', $dayOne?->name);

        Carbon::setTestNow('2026-07-08');
        $dayTwo = $service->resolvePolicy($reservation->fresh(['payments']));
        $this->assertSame('Same day of stay', $dayTwo?->name);

        Carbon::setTestNow('2026-07-09');
        $beforeHalf = $service->resolvePolicy($reservation->fresh(['payments']));
        $this->assertSame('Same day of stay', $beforeHalf?->name);

        Carbon::setTestNow('2026-07-10');
        $halfStay = $service->resolvePolicy($reservation->fresh(['payments']));
        $this->assertSame('After half of stay', $halfStay?->name);

        Carbon::setTestNow();
    }

    public function test_percent_threshold_after_start_applies_from_half_stay(): void
    {
        RefundPolicy::query()->where('during_stay', 1)->delete();
        RefundPolicy::create([
            'name' => 'Percent during stay — 50% of stay',
            'rent_type' => null,
            'timing' => 'after_start',
            'threshold_mode' => RefundPolicy::THRESHOLD_PERCENT_OF_STAY,
            'threshold_percent' => 50,
            'days_threshold' => 0,
            'days_before_checkin' => 0,
            'refund_percent' => 40,
            'refund_basis' => 'paid_net',
            'payment_status' => 2,
            'during_stay' => 1,
            'payment_statuses' => ['paid'],
        ]);

        Carbon::setTestNow('2026-05-25');

        $user = $this->userWithApiPermissions(['manage refunds']);
        $reservation = $this->createPaidReservation('2026-06-01', '2026-06-09', $user);
        $this->assertNotNull($reservation);

        Carbon::setTestNow('2026-06-04');
        $early = $this->actingAs($user, 'api')->getJson(
            '/api/users/refund-policies/preview?reservation_id='.$reservation->id
        );
        $early->assertStatus(422);

        Carbon::setTestNow('2026-06-05');
        $eligible = $this->actingAs($user, 'api')->getJson(
            '/api/users/refund-policies/preview?reservation_id='.$reservation->id
        );
        $eligible->assertStatus(200);
        $eligible->assertJsonPath('success', true);
        $this->assertEquals(4, (int) $eligible->json('data.breakdown.resolved_threshold_days'));

        Carbon::setTestNow();
    }

    public function test_partial_refund_preview_uses_selected_checkout_for_policy(): void
    {
        RefundPolicy::query()->delete();

        RefundPolicy::create([
            'name' => 'Same day of stay',
            'rent_type' => null,
            'timing' => 'after_start',
            'threshold_mode' => RefundPolicy::THRESHOLD_FIXED_DAYS,
            'days_threshold' => 0,
            'days_before_checkin' => 0,
            'refund_percent' => 50,
            'refund_basis' => 'total',
            'payment_status' => 2,
            'during_stay' => 1,
            'payment_statuses' => ['paid'],
        ]);

        RefundPolicy::create([
            'name' => 'After half of stay',
            'rent_type' => null,
            'timing' => 'after_start',
            'threshold_mode' => RefundPolicy::THRESHOLD_PERCENT_OF_STAY,
            'threshold_percent' => 50,
            'days_threshold' => 0,
            'days_before_checkin' => 0,
            'refund_percent' => 40,
            'refund_basis' => 'paid_net',
            'payment_status' => 2,
            'during_stay' => 1,
            'payment_statuses' => ['paid'],
        ]);

        Carbon::setTestNow('2026-07-06');

        $user = $this->userWithApiPermissions(['manage refunds']);
        $reservation = $this->createPaidReservation('2026-07-06', '2026-07-13', $user);
        $this->assertNotNull($reservation);

        $preview = $this->actingAs($user, 'api')->getJson(
            '/api/users/refund-policies/preview?reservation_id='.$reservation->id
            .'&new_expire_date=2026-07-07'
        );
        $preview->assertStatus(200);
        $preview->assertJsonPath('data.policy.name', 'Same day of stay');
        $this->assertEquals(1, (int) $preview->json('data.breakdown.days_since_start'));

        $previewHalf = $this->actingAs($user, 'api')->getJson(
            '/api/users/refund-policies/preview?reservation_id='.$reservation->id
            .'&new_expire_date=2026-07-11'
        );
        $previewHalf->assertStatus(200);
        $previewHalf->assertJsonPath('success', true);
        $previewHalf->assertJsonPath('data.policy.name', 'After half of stay');
        $this->assertEquals(5, (int) $previewHalf->json('data.breakdown.days_since_start'));

        Carbon::setTestNow();
    }

    public function test_refund_policy_crud(): void
    {
        $user = $this->userWithApiPermissions(['manage refund policies']);

        $create = $this->actingAs($user, 'api')->postJson('/api/users/refund-policies', [
            'name' => 'Test policy',
            'rent_type' => 0,
            'timing' => 'before_start',
            'threshold_mode' => 'fixed_days',
            'days_threshold' => 10,
            'refund_percent' => 75,
            'refund_basis' => 'total',
            'payment_statuses' => ['partial', 'full'],
        ]);
        $create->assertStatus(200);
        $id = $create->json('data.id');
        $this->assertNotNull($id);
        $create->assertJsonPath('data.payment_statuses', ['partial', 'full']);
        $this->assertNull($create->json('data.payment_status'));

        $this->actingAs($user, 'api')->getJson('/api/users/refund-policies')
            ->assertStatus(200);

        $update = $this->actingAs($user, 'api')->putJson("/api/users/refund-policies/{$id}", [
            'payment_statuses' => ['paid'],
        ]);
        $update->assertStatus(200)
            ->assertJsonPath('data.payment_statuses', ['paid']);

        $this->actingAs($user, 'api')->deleteJson('/api/users/refund-policies', ['id' => $id])
            ->assertStatus(200);
    }

    public function test_paid_payment_status_matches_partial_and_full_reservations(): void
    {
        Carbon::setTestNow('2026-06-01');

        $user = $this->userWithApiPermissions(['manage refund policies', 'manage refunds']);
        $reservation = $this->createPaidReservation('2026-06-15', '2026-06-20', $user);
        $this->assertNotNull($reservation);

        $policy = $this->actingAs($user, 'api')->postJson('/api/users/refund-policies', [
            'name' => 'Any paid — 7+ days',
            'rent_type' => 0,
            'timing' => 'before_start',
            'threshold_mode' => 'fixed_days',
            'days_threshold' => 7,
            'refund_percent' => 40,
            'refund_basis' => 'paid_net',
            'payment_statuses' => ['paid'],
        ]);
        $policy->assertStatus(200);

        $preview = $this->actingAs($user, 'api')->getJson(
            '/api/users/refund-policies/preview?reservation_id='.$reservation->id
        );
        $preview->assertStatus(200);
        $preview->assertJsonPath('success', true);
        $this->assertGreaterThan(0, (float) $preview->json('data.refund_amount'));

        Carbon::setTestNow();
    }

    private function createPaidReservation(string $startDate, string $endDate, \App\Models\User $user): ?Reservation
    {
        $this->artisan('db:seed', [
            '--class' => ReservationTestDataSeeder::class,
            '--force' => true,
            '--no-interaction' => true,
        ]);

        $client = Client::first();
        $stayReason = Stay_reason::first();
        $source = Reservation_source::first();
        if (! $client || ! $stayReason || ! $source) {
            return null;
        }

        $room = $this->findOrCreateAvailableRoom($startDate, $endDate)
            ?? $this->createIsolatedTestRoom();
        if (! $room) {
            return null;
        }

        $create = $this->actingAs($user, 'api')->postJson('/api/users/makeReservation', [
            'client_id' => $client->id,
            'rooms' => [['room_id' => $room->id]],
            'start_date' => $startDate,
            'expire_date' => $endDate,
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

        if ($create->status() !== 200 || ! $create->json('success')) {
            $this->fail('makeReservation failed: '.($create->json('message') ?? $create->getContent()));
        }

        $id = $create->json('data.id') ?? $create->json('data.reservation.id') ?? null;
        $reservation = $id ? Reservation::find($id) : Reservation::latest('id')->first();
        if (! $reservation) {
            return null;
        }

        $pay = $this->actingAs($user, 'api')->postJson(
            "/api/users/reservations/{$reservation->id}/payments",
            ['pay' => (float) $reservation->total, 'type' => ReservationPay::TYPE_PAYMENT]
        );

        if ($pay->status() !== 200 || ! $pay->json('success')) {
            return null;
        }

        return $reservation->fresh(['payments']);
    }
}
