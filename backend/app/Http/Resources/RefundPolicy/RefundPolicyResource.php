<?php

namespace App\Http\Resources\RefundPolicy;

use App\Models\RefundPolicy;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RefundPolicyResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'rent_type' => $this->rent_type,
            'timing' => $this->timing,
            'threshold_mode' => $this->threshold_mode ?? RefundPolicy::THRESHOLD_FIXED_DAYS,
            'threshold_percent' => $this->threshold_percent !== null
                ? (float) $this->threshold_percent
                : null,
            'days_threshold' => $this->days_threshold,
            'refund_basis' => $this->refund_basis,
            'days_before_checkin' => $this->days_before_checkin,
            'refund_percent' => $this->refund_percent,
            'payment_status' => $this->payment_status,
            'payment_statuses' => $this->resource->resolvedPaymentStatuses(),
            'during_stay' => $this->during_stay,
            'priority' => (int) ($this->priority ?? 0),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
