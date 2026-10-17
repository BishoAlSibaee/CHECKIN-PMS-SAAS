<?php

namespace App\Http\Requests\RoomType;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Contracts\Validation\Validator;

class UpdateRoomtypePricingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'id'             => 'required|exists:roomtype_pricingplan,id',
            'pricingplan_id' => 'required|exists:pricing_plans,id',
            'roomtype_id'    => 'required|exists:room_types,id',
            'NameAr'         => 'required|string|max:255',
            'NameEn'         => 'required|string|max:255',
            'StartDate'      => 'required|date',
            'EndDate'        => 'required|date|after_or_equal:StartDate',
            'DailyPrice'     => 'required|numeric|min:0',
            'MonthlyPrice'   => 'required|numeric|min:0',
            'ActiveType'     => 'required|numeric|in:0,1,2',
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
