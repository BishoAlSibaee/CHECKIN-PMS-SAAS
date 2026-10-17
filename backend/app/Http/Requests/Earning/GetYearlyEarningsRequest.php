<?php

namespace App\Http\Requests\Earning;

use Illuminate\Foundation\Http\FormRequest;

class GetYearlyEarningsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'years' => 'required|array|between:1,10',
            'years.*' => 'integer|min:2000|max:2040',
            'compare_years' => 'nullable|array|between:1,10',
            'compare_years.*' => 'integer|min:2000|max:2040',
        ];
    }
}

