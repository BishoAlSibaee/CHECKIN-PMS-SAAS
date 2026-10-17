<?php

namespace App\Http\Requests\Room;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Contracts\Validation\Validator;

class StoreMultiRoomRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
       return [
        'buildingId'   => 'required|numeric|exists:buildings,id',
        'floorId'      => [
            'required',
            'numeric',
            Rule::exists('floors', 'id')->where(fn ($q) => $q->where('building_id', $this->buildingId)),
        ],
        'numberOfRoom' => 'required|numeric|min:1',
        'numberRoom'   => [
            'required',
            'numeric',
            function ($attribute, $value, $fail) {
                $start = (int) $value;
                $end = $start + (int) $this->numberOfRoom;

                $exists = \App\Models\Room::where('building_id', $this->buildingId)
                    ->where('number', '>=', $start)
                    ->where('number', '<', $end)
                    ->exists();

                if ($exists) {
                    $fail('One or more room numbers in this range already exist in this building.');
                }
            },
        ],
        'typeRoom'     => 'required|numeric|exists:room_types,id',
        'capacity'     => 'nullable|numeric',
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
