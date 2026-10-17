<?php

namespace App\Http\Requests\Suite;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Validation\Rule;

class UpdateSuiteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'id' => 'required|numeric|exists:suites,id',
            'number' => [
                'nullable',
                'string',
                Rule::unique('suites', 'number')
                    ->where(function ($query) {
                        $suite = \App\Models\Suite::find($this->id);
                        return $query->where('building_id', $suite ? $suite->building_id : null);
                    })
                    ->ignore($this->id),
            ],
            'room_ids_to_add' => 'nullable|array',
            'room_ids_to_add.*' => 'numeric|exists:rooms,id',
            'room_ids_to_remove' => 'nullable|array',
            'room_ids_to_remove.*' => 'numeric|exists:rooms,id',
            'active' => 'nullable|boolean',
        ];
    }

    protected function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(response()->json([
            'success' => false,
            'message' => $validator->errors()->first(),
            'code' => 422,
            'data' => null
        ], 422));
    }
}
