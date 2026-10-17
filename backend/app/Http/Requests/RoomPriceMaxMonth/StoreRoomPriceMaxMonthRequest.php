<?php

namespace App\Http\Requests\RoomPriceMaxMonth;

use Illuminate\Foundation\Http\FormRequest;

class StoreRoomPriceMaxMonthRequest extends FormRequest
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
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array|string>
     */
    public function rules(): array
    {
        return [
            'room_price_id' => 'required|exists:room_prices,id',
            'month' => 'required|integer|min:1|max:12',
            'price' => 'required|numeric|min:0',
        ];
    }
}

