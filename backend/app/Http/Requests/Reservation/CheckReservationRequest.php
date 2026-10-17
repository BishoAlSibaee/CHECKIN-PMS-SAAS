<?php

namespace App\Http\Requests\Reservation;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Contracts\Validation\Validator;

class CheckReservationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'building_id' => 'required|numeric|exists:buildings,id',
            'floor_id'    => 'nullable|numeric',
            'capacity'    => 'nullable|numeric',
            'room_type'   => 'required|numeric|exists:room_types,id',
            'suite_id'    => 'nullable|numeric|exists:suites,id',
            'start_date'  => 'required|date',
            'expire_date' => 'required|date',
            'type_search' => 'required|numeric|in:1,2',
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

