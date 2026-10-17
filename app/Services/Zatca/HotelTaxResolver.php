<?php

namespace App\Services\Zatca;

use App\Models\Hotel;
use App\Models\HotelInvoiceCounter;
use App\Models\HotelTaxProfile;
use Illuminate\Support\Facades\DB;

/**
 * Resolves which hotel "owns" the tax registration a given branch reports
 * under, and issues sequential invoice numbers scoped to that owner. A branch
 * either has its own VAT/CR registration (the common case) or shares another
 * branch's (shares_tax_with_hotel_id) — sharing is one hop only: the target
 * of shares_tax_with_hotel_id must itself be a tax owner, enforced when the
 * link is created (see HotelsController).
 */
class HotelTaxResolver
{
    public function resolveEffectiveTaxHotelId(int $hotelId): int
    {
        $sharesWith = Hotel::query()->whereKey($hotelId)->value('shares_tax_with_hotel_id');

        return $sharesWith !== null ? (int) $sharesWith : $hotelId;
    }

    /**
     * CSR/seller-identity fields for the given (already-resolved) tax-owner
     * hotel. Falls back to config('zatca.csr') when that hotel has never had
     * a tax profile filled in — keeps the original single-tenant setup
     * working with zero data entry.
     *
     * @return array<string, string>
     */
    public function csrConfig(int $taxHotelId): array
    {
        $profile = HotelTaxProfile::query()->where('hotel_id', $taxHotelId)->first();
        $defaults = (array) config('zatca.csr');

        if ($profile === null) {
            return $defaults;
        }

        return array_merge($defaults, [
            'organization_identifier' => $profile->vat_number,
            'organization_unit_name' => $profile->org_unit_name ?: $defaults['organization_unit_name'],
            'organization_name' => $profile->org_name_en,
            'location' => $profile->location ?: $defaults['location'],
            'industry' => $profile->industry ?: $defaults['industry'],
        ]);
    }

    /**
     * Atomically claims the next number in the given tax entity's invoice
     * sequence (shared across every branch reporting under that entity).
     */
    public function nextInvoiceNumber(int $taxHotelId, string $prefix): string
    {
        $number = DB::transaction(function () use ($taxHotelId) {
            $counter = HotelInvoiceCounter::query()
                ->where('tax_hotel_id', $taxHotelId)
                ->lockForUpdate()
                ->first();

            if ($counter === null) {
                $counter = HotelInvoiceCounter::create(['tax_hotel_id' => $taxHotelId, 'next_number' => 1]);
            }

            $claimed = (int) $counter->next_number;
            $counter->increment('next_number');

            return $claimed;
        });

        return $prefix.str_pad((string) $number, 6, '0', STR_PAD_LEFT);
    }
}
