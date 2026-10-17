<?php

namespace App\Http\Requests\Facilities;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Contracts\Validation\Validator;

class UpdateFacilitiesRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'id' => 'required|numeric|exists:facilities,id',
            'name_ar' => 'nullable|string|max:255',
            'name_en' => 'nullable|string|max:255',
            'building_id' => 'nullable|numeric|exists:buildings,id',
            'floor_id' => 'nullable|numeric|exists:floors,id',
            'facilities_types_id' => 'nullable|numeric|exists:facilities_type,id',
            'lock_data' => 'nullable|string|unique:facilities,lock_data',
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
