<?php

namespace Tests\Support;

use App\Models\Reservation;
use App\Models\ReservationDailyCharge;
use App\Services\PricingEngine;
use PHPUnit\Framework\Assert;

trait FinanceAssertions
{
    protected const TAX_RATE = 0.15;

    protected function assertReservationFinancialContract(Reservation $reservation, string $context = ''): void
    {
        $prefix = $context ? "{$context}: " : '';

        $base = (float) $reservation->base_price;
        $classificationDiscount = (float) $reservation->classification_discount;
        $discount = (float) $reservation->discount;
        $extras = (float) $reservation->extras;
        $penalties = (float) $reservation->penalties;
        $subtotal = (float) $reservation->subtotal;
        $taxes = (float) $reservation->taxes;
        $total = (float) $reservation->total;

        $expectedSubtotal = round($base - $classificationDiscount - $discount + $extras + $penalties, 2);
        Assert::assertEqualsWithDelta(
            $expectedSubtotal,
            $subtotal,
            0.02,
            $prefix.'subtotal = base - classification_discount - discount + extras + penalties'
        );

        $expectedTaxes = round($subtotal * self::TAX_RATE, 2, PHP_ROUND_HALF_UP);
        Assert::assertEqualsWithDelta($expectedTaxes, $taxes, 0.02, $prefix.'taxes = 15% of subtotal');

        Assert::assertEqualsWithDelta($subtotal + $taxes, $total, 0.02, $prefix.'total = subtotal + taxes');

        $chargeSum = (float) ReservationDailyCharge::where('reservation_id', $reservation->id)->sum('base_amount');
        Assert::assertEqualsWithDelta($base, $chargeSum, 0.02, $prefix.'base_price = sum(daily charges)');
    }

    protected function assertPricingEngineMatchesReservation(
        PricingEngine $engine,
        Reservation $reservation
    ): void {
        foreach ($reservation->reservationRooms as $resRoom) {
            if (! $resRoom->room) {
                continue;
            }

            $lines = $engine->buildDailyBreakdown(
                $resRoom->room,
                $reservation->start_date,
                $reservation->expire_date,
                (int) $reservation->rent_type,
                (int) ($reservation->price_calculation_mode ?? 0)
            );

            $stored = (float) ReservationDailyCharge::where('reservation_room_id', $resRoom->id)->sum('base_amount');
            Assert::assertEqualsWithDelta(
                $engine->sumBaseAmount($lines),
                $stored,
                0.05,
                "Repricing reservation {$reservation->id} room {$resRoom->id}"
            );
        }
    }

    /**
     * @param  array{summary?: array<int, array{label: string, value: mixed}>}  $reportData
     */
    protected function reportSummaryValue(array $reportData, string $label): ?float
    {
        foreach ($reportData['summary'] ?? [] as $item) {
            if (($item['label'] ?? '') === $label) {
                return is_numeric($item['value']) ? (float) $item['value'] : null;
            }
        }

        return null;
    }

    /**
     * @param  array{summary?: array<int, array{label: string, value: mixed}>}  $reportData
     */
    protected function reportSummaryString(array $reportData, string $label): ?string
    {
        foreach ($reportData['summary'] ?? [] as $item) {
            if (($item['label'] ?? '') === $label) {
                $value = $item['value'] ?? null;

                return is_scalar($value) ? (string) $value : null;
            }
        }

        return null;
    }

    /**
     * @param  array{summary?: array<int, array{label: string, value: mixed}>}  $reportData
     */
    protected function assertReportSummaryEquals(
        array $reportData,
        string $label,
        float $expected,
        float $delta = 0.10,
        string $context = ''
    ): void {
        $actual = $this->reportSummaryValue($reportData, $label);
        $prefix = $context !== '' ? "{$context}: " : '';
        Assert::assertNotNull($actual, $prefix."summary label missing: {$label}");
        Assert::assertEqualsWithDelta($expected, $actual, $delta, $prefix.$label);
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    protected function assertRowSumEquals(array $rows, string $key, float $expected, float $delta = 0.10, string $context = ''): void
    {
        $sum = round(collect($rows)->sum(fn ($r) => (float) ($r[$key] ?? 0)), 2);
        $prefix = $context !== '' ? "{$context}: " : '';
        Assert::assertEqualsWithDelta($expected, $sum, $delta, $prefix."sum({$key})");
    }

    /**
     * @param  array{summary?: array<int, array{label: string, value: mixed}>}  $reportData
     */
    protected function parsePercentSummary(array $reportData, string $label): float
    {
        foreach ($reportData['summary'] ?? [] as $item) {
            if (($item['label'] ?? '') !== $label) {
                continue;
            }
            $raw = $item['value'] ?? null;
            if (is_numeric($raw)) {
                return (float) $raw;
            }
            if (is_string($raw)) {
                return (float) str_replace('%', '', trim($raw));
            }
        }

        return 0.0;
    }
}
