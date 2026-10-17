<?php

namespace App\Http\Requests\RefundPolicy;

use App\Support\RefundPolicyPaymentStatus;
use Illuminate\Foundation\Http\FormRequest;

class UpdateRefundPolicyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'sometimes|string|max:255',
            'rent_type' => 'nullable|integer|in:0,1,2',
            'timing' => 'sometimes|in:before_start,after_start',
            'threshold_mode' => 'sometimes|in:fixed_days,percent_of_stay',
            'threshold_percent' => 'sometimes|nullable|numeric|min:0|max:100',
            'days_threshold' => 'sometimes|nullable|integer|min:0',
            'refund_percent' => 'sometimes|numeric|min:0|max:100',
            'refund_basis' => 'sometimes|in:total,remaining_nights,paid_net',
            'payment_status' => 'sometimes|nullable|integer|in:1,2',
            'payment_statuses' => 'sometimes|nullable|array',
            'payment_statuses.*' => 'string|in:partial,full,paid',
            'days_before_checkin' => 'nullable|integer|min:0',
            'during_stay' => 'nullable|in:0,1',
            'priority' => 'nullable|integer|in:0,1',
        ];
    }

    protected function prepareForValidation(): void
    {
        $merge = [];

        if ($this->has('timing')) {
            $merge['during_stay'] = $this->input('timing') === 'after_start' ? 1 : 0;
        }
        if ($this->has('days_threshold')) {
            $merge['days_before_checkin'] = $this->input('days_threshold');
        }
        $mode = $this->input('threshold_mode');
        if ($mode === 'percent_of_stay') {
            $merge['days_threshold'] = 0;
            if ($this->has('priority')) {
                $merge['priority'] = (int) $this->input('priority', 0);
            }
        } elseif ($mode === 'fixed_days') {
            $merge['priority'] = 0;
        }
        if ($this->has('payment_statuses')) {
            $statuses = RefundPolicyPaymentStatus::normalize($this->input('payment_statuses'));
            $merge['payment_statuses'] = $statuses ?: null;
            $merge['payment_status'] = RefundPolicyPaymentStatus::legacySingleStatus($statuses);
        }

        if ($merge !== []) {
            $this->merge($merge);
        }
    }
}
