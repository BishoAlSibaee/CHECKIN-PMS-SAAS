<?php

namespace App\Http\Requests\RoomType;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Contracts\Validation\Validator;

class AddRoomTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $rules = [
            'name_ar'     => 'required|string|max:255|unique:room_types,name_ar',
            'name_en'     => 'required|string|max:255|unique:room_types,name_en',
            'description' => 'required|string|max:255',
            'active_type' => 'required|numeric|in:0,1,2',
            'dynamic_pricing_enabled' => 'nullable|boolean',
        ];

        if ($this->active_type == 0) {
            $rules['Min_daily_price'] = 'required|numeric|min:1';
            $rules['Min_monthly_price'] = 'required|numeric|min:1';
        } else {
            $rules['Min_daily_price'] = 'required|numeric|min:1';
            $rules['Max_daily_price'] = 'required|numeric|min:1';
            $rules['Min_monthly_price'] = 'required|numeric|min:1';
            $rules['Max_monthly_price'] = 'required|numeric|min:1';
        }

        return $rules;
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
