<?php

namespace App\Http\Requests\Suite;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Validation\Rule;

class AddSuiteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $buildingId = $this->input('building_id');

        return [
            'building_id' => 'required|numeric|exists:buildings,id',
            'floor_id'    => 'required|numeric|exists:floors,id',
            'number'      => [
                'required',
                'string',
                'max:100',
                Rule::unique('suites', 'number')->where(function ($query) use ($buildingId) {
                    return $query->where('building_id', $buildingId);
                }),
            ],
            'lock_id'     => 'nullable|string|unique:suites,lock_id',
            'rooms'       => 'required|array|min:1',
            'rooms.*.id'  => 'required|exists:rooms,id',
        ];
    }

    protected function failedValidation(Validator $validator)
    {
        $response = response()->json([
            'success' => false,
            'message' => $validator->errors()->first(),
            'code'    => 422,
            'data'    => null
        ], 422);

        throw new HttpResponseException($response);
    }
}
