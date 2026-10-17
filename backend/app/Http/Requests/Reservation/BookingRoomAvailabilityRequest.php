<?php

namespace App\Http\Requests\Reservation;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Contracts\Validation\Validator;

class BookingRoomAvailabilityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'building_id' => 'required|numeric|exists:buildings,id',
            'start_date'  => 'required|date',
            'expire_date' => 'required|date|after:start_date',
            'floor_id'    => 'nullable|numeric',
            'room_type_id' => 'nullable|numeric|exists:room_types,id',
            'search'      => 'nullable|string|max:100',
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
