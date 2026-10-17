<?php

namespace App\Http\Requests\Earning;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GetEarningsRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
'scope' => ['required', Rule::in(['room', 'suite', 'floor', 'building', 'roomtype'])],
            'entity_id' => ['required', 'integer', 'min:1'],
            'compare_start_date' => ['nullable', 'date_format:Y-m-d'],
            'compare_end_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:compare_start_date'],
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'scope.in' => 'Scope must be one of: room, suite, floor, building.',
            'entity_id.min' => 'Entity ID must be a positive integer.',
            'end_date.after_or_equal' => 'End date must be after or equal to start date.',
        ];
    }
}

