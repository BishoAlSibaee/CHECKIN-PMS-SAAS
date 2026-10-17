<?php

namespace App\Http\Requests\Reservation;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Contracts\Validation\Validator;

class GetRoomPriceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'startDate'        => 'required|date',
            'endDate'          => 'required|date|after:startDate',
            'roomTypeId'       => 'required|numeric|exists:room_types,id',
            'typeReservation'  => 'required|in:0,1,2',
            'numberOfMonths'   => 'nullable|numeric|min:1',
            'price_calculation_mode' => 'nullable|in:0,1,2',
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

