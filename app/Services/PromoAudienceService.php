<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Client_Classifications;
use Illuminate\Support\Collection;

/**
 * Resolves the target audience for a promo campaign draft. Client and
 * Guest_classification live on separate DB connections (mysql2 / mysql) —
 * no single Eloquent relationship crosses that boundary, so this is always
 * two manual queries, matching GuestClassificationDiscountService's own
 * pattern.
 */
class PromoAudienceService
{
    /**
     * @param  array<int, int>  $clientIds
     * @return Collection<int, Client>
     */
    public function manualAudience(array $clientIds, int $tenantId): Collection
    {
        if ($clientIds === []) {
            return collect();
        }

        return Client::where('tenant_id', $tenantId)
            ->whereIn('id', $clientIds)
            ->get(['id', 'first_name', 'last_name', 'international_code', 'mobile']);
    }

    /**
     * @return Collection<int, Client>
     */
    public function classificationAudience(int $guestClassificationId, int $tenantId): Collection
    {
        $clientIds = Client_Classifications::where('classifications_id', $guestClassificationId)->pluck('client_id');

        if ($clientIds->isEmpty()) {
            return collect();
        }

        return Client::where('tenant_id', $tenantId)
            ->whereIn('id', $clientIds)
            ->get(['id', 'first_name', 'last_name', 'international_code', 'mobile']);
    }

    /**
     * International format without a leading "+"/"00", as Taqnyat's API
     * expects — e.g. international_code "966" + mobile "0501234567" (minus
     * its leading zero) => "966501234567".
     */
    public function formatInternationalNumber(?string $internationalCode, ?string $mobile): ?string
    {
        if (! $mobile) {
            return null;
        }

        $code = preg_replace('/\D+/', '', (string) $internationalCode) ?: '';
        $local = preg_replace('/\D+/', '', $mobile) ?: '';
        $local = ltrim($local, '0');

        if ($code === '' || $local === '') {
            return null;
        }

        return $code.$local;
    }
}
