<?php

namespace App\Http\Requests\RefundPolicy;

use Illuminate\Foundation\Http\FormRequest;

class PreviewRefundPolicyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reservation_id' => 'required|integer|exists:reservations,id',
            'new_expire_date' => 'nullable|date',
        ];
    }
}
