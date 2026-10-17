<?php

namespace App\Http\Requests\Earning;

use Illuminate\Foundation\Http\FormRequest;
use Carbon\Carbon;

class EarningsSummaryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
        ];
    }
}
