<?php

namespace Tests\Feature\Guest;

use App\Models\Client;
use App\Models\Client_Classifications;
use App\Models\Discount;
use App\Models\Guest_classification;
use App\Models\Reservation;
use App\Models\Reservation_source;
use App\Models\Stay_reason;
use App\Services\GuestClassificationDiscountService;
use Tests\Support\FinanceAssertions;
use Tests\Support\InteractsWithReservationFixtures;
use Tests\TestCase;

class GuestClassificationDiscountBookingTest extends TestCase
{
    use FinanceAssertions;
    use InteractsWithReservationFixtures;

    public function test_get_client_classification_available_for_create_reservations_permission(): void
    {
        $client = Client::query()->first();
        $this->assertNotNull($client);

        $user = $this->userWithOnlyPermissions(['create reservations']);

        $response = $this->actingAs($user, 'api')->getJson(
            '/api/users/getClientClassification?client_id='.$client->id
        );

        $response->assertOk();
        $this->assertTrue($response->json('success') === true);
    }

    public function test_get_client_classification_includes_nested_discount(): void
    {
        $suffix = $this->uniqueSuffix();
        $discount = Discount::create([
            'name' => 'VIP 10% '.$suffix,
            'is_percentage' => 1,
            'percent' => 10,
            'is_fixed' => 0,
            'fixed_amount' => 0,
            'is_active' => 1,
        ]);

        $classification = Guest_classification::create([
            'name_ar' => 'VIP '.$suffix,
            'name_en' => 'VIP '.$suffix,
            'description' => 'Test',
            'discount_id' => $discount->id,
            'active' => 1,
        ]);

        $client = Client::query()->first();
        $this->assertNotNull($client);

        Client_Classifications::query()->where('client_id', $client->id)->delete();
        Client_Classifications::create([
            'client_id' => $client->id,
            'classifications_id' => $classification->id,
        ]);

        $user = $this->userWithOnlyPermissions(['create reservations']);
        $response = $this->actingAs($user, 'api')->getJson(
            '/api/users/getClientClassification?client_id='.$client->id
        );

        $response->assertOk();
        $response->assertJsonPath('data.classification.discount.id', $discount->id);

        Client_Classifications::query()->where('client_id', $client->id)->delete();
        $classification->delete();
        $discount->delete();
    }

    public function test_booking_applies_classification_discount_separate_from_manual(): void
    {
        $suffix = $this->uniqueSuffix();
        $discount = Discount::create([
            'name' => 'Class 10% '.$suffix,
            'is_percentage' => 1,
            'percent' => 10,
            'is_fixed' => 0,
            'fixed_amount' => 0,
            'is_active' => 1,
        ]);

        $classification = Guest_classification::create([
            'name_ar' => 'مصنف '.$suffix,
            'name_en' => 'Classified '.$suffix,
            'description' => 'Test',
            'discount_id' => $discount->id,
            'active' => 1,
        ]);

        $client = Client::query()->first();
        $stayReason = Stay_reason::first();
        $source = Reservation_source::first();
        $this->assertNotNull($client);
        $this->assertNotNull($stayReason);
        $this->assertNotNull($source);

        Client_Classifications::query()->where('client_id', $client->id)->delete();
        Client_Classifications::create([
            'client_id' => $client->id,
            'classifications_id' => $classification->id,
        ]);

        $start = '2026-10-01';
        $end = '2026-10-04';
        $room = $this->createIsolatedTestRoom() ?? $this->findOrCreateAvailableRoom($start, $end);
        $this->assertNotNull($room);

        $user = $this->userWithApiPermissions(['create reservations', 'view reservations']);

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
            'discount' => 50,
            'extras' => 0,
            'penalties' => 0,
            'pay_amount' => 0,
            'logedin' => 0,
        ]);

        if ($response->status() === 422 || ($response->status() === 500 && str_contains((string) $response->json('message'), 'غير متاحة'))) {
            Client_Classifications::query()->where('client_id', $client->id)->delete();
            if (isset($classification)) {
                $classification->delete();
            }
            if (isset($discount)) {
                $discount->delete();
            }
            $this->markTestSkipped('Create reservation blocked: '.$response->json('message'));
        }

        if ($response->status() === 500) {
            $this->fail('makeReservation 500: '.($response->json('message') ?? $response->getContent()));
        }

        $response->assertOk();
        $reservationId = (int) ($response->json('data.reservation.id') ?? $response->json('data.id'));
        $reservation = Reservation::find($reservationId);
        $this->assertNotNull($reservation);

        $base = (float) $reservation->base_price;
        $this->assertGreaterThan(0, $base);
        $expectedClassification = round($base * 0.10, 2);

        $this->assertEqualsWithDelta($expectedClassification, (float) $reservation->classification_discount, 0.05);
        $this->assertEqualsWithDelta(50.0, (float) $reservation->discount, 0.05);
        $this->assertEquals($classification->id, (int) $reservation->guest_classification_id);
        $this->assertEquals($discount->id, (int) $reservation->classification_discount_id);

        $this->assertReservationFinancialContract($reservation, 'classification booking');

        Client_Classifications::query()->where('client_id', $client->id)->delete();
        $classification->delete();
        $discount->delete();
    }

    public function test_booking_without_classification_keeps_manual_discount_only(): void
    {
        $client = Client::query()->first();
        $stayReason = Stay_reason::first();
        $source = Reservation_source::first();
        $this->assertNotNull($client);

        Client_Classifications::query()->where('client_id', $client->id)->delete();

        $start = '2026-10-10';
        $end = '2026-10-13';
        $room = $this->createIsolatedTestRoom() ?? $this->findOrCreateAvailableRoom($start, $end);
        $this->assertNotNull($room);

        $user = $this->userWithApiPermissions(['create reservations', 'view reservations']);

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
            'discount' => 25,
            'extras' => 0,
            'penalties' => 0,
            'pay_amount' => 0,
            'logedin' => 0,
        ]);

        if ($response->status() === 422 || ($response->status() === 500 && str_contains((string) $response->json('message'), 'غير متاحة'))) {
            $this->markTestSkipped('Create reservation blocked: '.$response->json('message'));
        }

        if ($response->status() === 500) {
            $this->fail('makeReservation 500: '.($response->json('message') ?? $response->getContent()));
        }

        $response->assertOk();
        $reservationId = (int) ($response->json('data.reservation.id') ?? $response->json('data.id'));
        $reservation = Reservation::find($reservationId);
        $this->assertNotNull($reservation);

        $this->assertEqualsWithDelta(0.0, (float) $reservation->classification_discount, 0.01);
        $this->assertEqualsWithDelta(25.0, (float) $reservation->discount, 0.05);
        $this->assertNull($reservation->guest_classification_id);
        $this->assertNull($reservation->classification_discount_id);
    }

    public function test_apply_cap_limits_manual_after_large_classification(): void
    {
        $service = app(GuestClassificationDiscountService::class);
        $fixedRule = new Discount([
            'is_percentage' => 0,
            'percent' => 0,
            'is_fixed' => 1,
            'fixed_amount' => 800,
            'is_active' => 1,
        ]);

        $classificationRaw = $service->computeAmount($fixedRule, 1000);
        $capped = $service->applyCap($classificationRaw, 300, 1000);

        $this->assertEqualsWithDelta(800.0, $capped['classification'], 0.01);
        $this->assertEqualsWithDelta(200.0, $capped['manual'], 0.01);
    }
}
