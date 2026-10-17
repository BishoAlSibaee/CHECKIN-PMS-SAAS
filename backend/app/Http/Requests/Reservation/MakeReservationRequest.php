<?php

namespace App\Http\Requests\Reservation;

use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Contracts\Validation\Validator;

class MakeReservationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'client_id'             => 'required|numeric',
            'rooms'                 => 'required|array|min:1',
            'rooms.*.room_id'       => 'nullable|numeric|exists:rooms,id',
            'rooms.*.suite_id'      => 'nullable|numeric|exists:suites,id',
            'start_date'            => 'required|date|after_or_equal:today',
            'expire_date'           => 'required|date|after:start_date',
            'reservation_type'      => 'required|in:0,1',
            'reservation_status'    => 'nullable|in:0,1,2,3',
            'stay_reason_id'        => 'required|numeric|exists:stay_reasons,id',
            'reservation_source_id' => 'required|numeric|exists:reservation_sources,id',
            'rent_type'             => 'required|in:0,1',
            'price_calculation_mode'=> 'required|in:0,1,2',
            'discount'              => 'nullable|numeric|min:0',
            'extras'                => 'nullable|numeric|min:0',
            'penalties'             => 'nullable|numeric|min:0',
            'logedin'               => 'nullable|in:0,1',
            'login_time'            => 'nullable|date',
            'pay_amount'            => 'nullable|numeric|min:0',
        ];
    }

    public function withValidator($validator)
    {
        if ($validator->fails()) return;

        $validator->after(function ($validator) {

            foreach ($this->input('rooms', []) as $index => $roomData) {
                if (empty($roomData['room_id']) && empty($roomData['suite_id'])) {
                    $validator->errors()->add('rooms.' . $index, 'Either room_id or suite_id must be provided for each room.');
                }
            }

            if ($this->input('rent_type') == 1) {
                try {
                    $startDate = Carbon::parse($this->input('start_date'))->startOfDay();
                    $expireDate = Carbon::parse($this->input('expire_date'))->startOfDay();

                    $minExpireDate = $startDate->copy()->addMonthNoOverflow();

                    if ($expireDate->lessThan($minExpireDate)) {
                        $validator->errors()->add(
                            'expire_date',
                            'مدة الحجز للإيجار الشهري غير كافية. بناءً على تاريخ البداية، يجب أن يكون تاريخ الانتهاء على الأقل: ' . $minExpireDate->format('Y/m/d')
                        );
                    }
                } catch (\Exception $e) {
                    $validator->errors()->add('expire_date', 'صيغة التاريخ غير صحيحة.');
                }
            }
        });
    }

    protected function failedValidation(Validator $validator)
    {
        $response = response()->json([
            'success' => false,
            'message' => $validator->errors()->first(),
            'code'    => 422,
            'data'    => $validator->errors()
        ], 422);

        throw new HttpResponseException($response);
    }
}
