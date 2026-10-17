<?php

namespace App\Http\Requests\Kiosk;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Contracts\Validation\Validator;

class KioskReservationLookupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'code' => 'required|string|max:8',
            'last_name' => 'nullable|string|max:255',
            'mobile' => 'nullable|string|max:20',
        ];
    }

    public function withValidator($validator)
    {
        if ($validator->fails()) {
            return;
        }

        $validator->after(function ($validator) {
            if (!$this->filled('last_name') && !$this->filled('mobile')) {
                $validator->errors()->add('last_name', 'Provide the guest last name or mobile number to verify identity.');
            }
        });
    }

    protected function failedValidation(Validator $validator)
    {
        $response = response()->json([
            'success' => false,
            'message' => $validator->errors()->first(),
            'code' => 422,
            'data' => null,
        ], 422);
        throw new HttpResponseException($response);
    }
}
