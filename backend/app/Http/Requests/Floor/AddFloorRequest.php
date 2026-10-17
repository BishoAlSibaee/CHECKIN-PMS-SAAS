<?php

namespace App\Http\Requests\Floor;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Validation\Rule;

class AddFloorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'building_id' => 'required|numeric|exists:buildings,id',
            'number' => [
                'required',
                'numeric',
                Rule::unique('floors', 'number')->where(function ($query) {
                    return $query->where('building_id', $this->building_id);
                }),
            ],
            'lock_id' => 'nullable|string|unique:floors,lock_id',
        ];
    }

    public function messages(): array
    {
        return [
            'number.unique' => 'The floor number already exists in this building.',
        ];
    }

    protected function failedValidation(Validator $validator)
    {
        $response = response()->json([
            'success' => false,
            'message' => $validator->errors()->first(),
            'code' => 422,
            'data' => null
        ], 422);
        throw new HttpResponseException($response);
    }
}
