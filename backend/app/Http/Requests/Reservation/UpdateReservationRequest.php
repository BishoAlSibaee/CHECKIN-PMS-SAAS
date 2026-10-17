<?php

namespace App\Http\Requests\Reservation;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class UpdateReservationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reservation_status' => 'nullable|in:0,1,2,3',
            'logedin'            => 'nullable|in:0,1',
            'login_time'         => 'nullable|date',
            'start_date'         => 'nullable|date',
            'expire_date'        => 'nullable|date|after:start_date',
            'discount'           => 'nullable|numeric|min:0',
            'extras'             => 'nullable|numeric|min:0',
            'penalties'          => 'nullable|numeric|min:0',
        ];
    }

    protected function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(response()->json([
            'success' => false,
            'message' => $validator->errors()->first(),
            'code'    => 422,
            'data'    => null,
        ], 422));
    }
}
