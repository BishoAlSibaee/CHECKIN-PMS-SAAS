<?php

namespace App\Http\Requests\Building;


use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Contracts\Validation\Validator;
class BuildingIdRequest extends FormRequest
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
        'id_building' => 'required|numeric|exists:buildings,id',

        ];
    }
 protected function failedValidation(Validator $validator){
        $response = response()->json([
            'success'=>false,
            'message'=>$validator->errors()->first(),
            'code'=>422,
            'data'=>null
        ],422);
        throw new httpResponseException($response);

    }
}
