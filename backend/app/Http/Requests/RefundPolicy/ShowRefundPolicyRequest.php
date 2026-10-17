<?php

namespace App\Http\Requests\RefundPolicy;

use Illuminate\Foundation\Http\FormRequest;

class ShowRefundPolicyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [];
    }
}

