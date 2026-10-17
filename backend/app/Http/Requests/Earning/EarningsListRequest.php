<?php

namespace App\Http\Requests\Earning;

use Illuminate\Foundation\Http\FormRequest;

class EarningsListRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'type' => 'in:all,in,out',
        ];
    }
}
