<?php

namespace App\Http\Requests\GroupBooking;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Validation rules for `members.*` deliberately duplicate
 * MakeReservationRequest's own room/date/stay-reason rules rather than
 * trying to compose FormRequest instances (Laravel FormRequests aren't
 * designed to be nested/reused that way) — this duplicates validation RULES
 * only, not pricing logic, which stays fully inside makeReservation().
 */
class StoreGroupBookingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'company_name' => 'nullable|string|max:255',
            'contact_name' => 'nullable|string|max:255',
            'contact_mobile' => 'nullable|string|max:50',
            'contact_email' => 'nullable|email|max:255',
            'notes' => 'nullable|string|max:2000',
            'group_discount_percent' => 'nullable|numeric|min:0|max:100',

            'members' => 'required|array|min:1',
            'members.*.client_id' => 'required|numeric',
            'members.*.rooms' => 'required|array|min:1',
            'members.*.rooms.*.room_id' => 'nullable|numeric|exists:rooms,id',
            'members.*.rooms.*.suite_id' => 'nullable|numeric|exists:suites,id',
            'members.*.start_date' => 'required|date|after_or_equal:today',
            'members.*.expire_date' => 'required|date|after:members.*.start_date',
            'members.*.stay_reason_id' => 'required|numeric|exists:stay_reasons,id',
            'members.*.reservation_source_id' => 'required|numeric|exists:reservation_sources,id',
            'members.*.reservation_status' => 'nullable|in:0,1,2,3',
            'members.*.rent_type' => 'required|in:0,1',
            'members.*.price_calculation_mode' => 'required|in:0,1,2',
            'members.*.discount' => 'nullable|numeric|min:0',
            'members.*.extras' => 'nullable|numeric|min:0',
            'members.*.penalties' => 'nullable|numeric|min:0',
            'members.*.pay_amount' => 'nullable|numeric|min:0',
        ];
    }

    public function withValidator($validator)
    {
        if ($validator->fails()) {
            return;
        }

        $validator->after(function ($validator) {
            foreach ($this->input('members', []) as $index => $member) {
                foreach (($member['rooms'] ?? []) as $roomIndex => $roomData) {
                    if (empty($roomData['room_id']) && empty($roomData['suite_id'])) {
                        $validator->errors()->add(
                            "members.{$index}.rooms.{$roomIndex}",
                            'Either room_id or suite_id must be provided for each room.'
                        );
                    }
                }
            }
        });
    }

    protected function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(response()->json([
            'success' => false,
            'message' => $validator->errors()->first(),
            'code' => 422,
            'data' => $validator->errors(),
        ], 422));
    }
}
