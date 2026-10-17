<?php

namespace App\Http\Requests\RoomPrice;

use Illuminate\Foundation\Http\FormRequest;

class AddMaxMinMonthRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'max_month' => 'nullable|numeric|min:0',
            'min_month' => 'nullable|numeric|min:0',
        ];
    }
}

