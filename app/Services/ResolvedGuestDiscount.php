<?php

namespace App\Services;

use App\Models\Discount;
use App\Models\Guest_classification;

final class ResolvedGuestDiscount
{
    public function __construct(
        public readonly int $guestClassificationId,
        public readonly int $classificationDiscountId,
        public readonly Discount $discount,
        public readonly ?Guest_classification $classification = null,
    ) {}
}
