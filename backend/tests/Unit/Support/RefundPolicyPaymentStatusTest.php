<?php

namespace Tests\Unit\Support;

use App\Support\RefundPolicyPaymentStatus;
use PHPUnit\Framework\TestCase;

class RefundPolicyPaymentStatusTest extends TestCase
{
    public function test_empty_statuses_match_any_payment(): void
    {
        $this->assertTrue(RefundPolicyPaymentStatus::matches(null, 0, 0));
        $this->assertTrue(RefundPolicyPaymentStatus::matches([], 2, 500));
    }

    public function test_paid_matches_partial_and_full_but_not_none(): void
    {
        $statuses = [RefundPolicyPaymentStatus::PAID];

        $this->assertTrue(RefundPolicyPaymentStatus::matches($statuses, 1, 100));
        $this->assertTrue(RefundPolicyPaymentStatus::matches($statuses, 2, 500));
        $this->assertFalse(RefundPolicyPaymentStatus::matches($statuses, 0, 0));
    }

    public function test_multiple_statuses_match_any_selected(): void
    {
        $statuses = [RefundPolicyPaymentStatus::PARTIAL, RefundPolicyPaymentStatus::FULL];

        $this->assertTrue(RefundPolicyPaymentStatus::matches($statuses, 1, 50));
        $this->assertTrue(RefundPolicyPaymentStatus::matches($statuses, 2, 500));
        $this->assertFalse(RefundPolicyPaymentStatus::matches($statuses, 0, 0));
    }

    public function test_legacy_single_status_derivation(): void
    {
        $this->assertSame(1, RefundPolicyPaymentStatus::legacySingleStatus(['partial']));
        $this->assertNull(RefundPolicyPaymentStatus::legacySingleStatus(['partial', 'full']));
        $this->assertNull(RefundPolicyPaymentStatus::legacySingleStatus(['paid']));
    }
}
