<?php

namespace Tests\Unit\Services;

use App\Models\Discount;
use App\Models\Reservation;
use App\Services\GuestClassificationDiscountService;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class GuestClassificationDiscountServiceTest extends TestCase
{
    private GuestClassificationDiscountService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(GuestClassificationDiscountService::class);
    }

    #[Test]
    public function compute_amount_applies_percentage_and_fixed(): void
    {
        $percentRule = new Discount([
            'is_percentage' => 1,
            'percent' => 10,
            'is_fixed' => 0,
            'fixed_amount' => 0,
        ]);

        $this->assertEqualsWithDelta(100.0, $this->service->computeAmount($percentRule, 1000), 0.01);

        $fixedRule = new Discount([
            'is_percentage' => 0,
            'percent' => 0,
            'is_fixed' => 1,
            'fixed_amount' => 50,
        ]);

        $this->assertEqualsWithDelta(50.0, $this->service->computeAmount($fixedRule, 1000), 0.01);
    }

    #[Test]
    public function apply_cap_uses_classification_first_then_manual(): void
    {
        $capped = $this->service->applyCap(800, 300, 1000);

        $this->assertEqualsWithDelta(800.0, $capped['classification'], 0.01);
        $this->assertEqualsWithDelta(200.0, $capped['manual'], 0.01);
    }

    #[Test]
    public function calculate_totals_subtracts_both_discounts(): void
    {
        $totals = $this->service->calculateTotals(1000, 100, 50, 0, 0);

        $this->assertEqualsWithDelta(850.0, $totals['subtotal'], 0.01);
        $this->assertEqualsWithDelta(127.5, $totals['taxes'], 0.02);
        $this->assertEqualsWithDelta(977.5, $totals['total'], 0.02);
    }

    #[Test]
    public function recalculate_for_reservation_updates_percentage_classification_when_base_grows(): void
    {
        $discount = Discount::create([
            'name' => 'Recalc 10% '.uniqid(),
            'is_percentage' => 1,
            'percent' => 10,
            'is_fixed' => 0,
            'fixed_amount' => 0,
            'is_active' => 1,
        ]);

        $reservation = new Reservation([
            'base_price' => 1000,
            'classification_discount_id' => $discount->id,
            'classification_discount' => 100,
            'discount' => 20,
        ]);

        $reservation->base_price = 2000;
        $amounts = $this->service->recalculateForReservation($reservation);

        $this->assertEqualsWithDelta(200.0, $amounts['classification_discount'], 0.01);
        $this->assertEqualsWithDelta(20.0, $amounts['discount'], 0.01);

        $discount->delete();
    }
}
