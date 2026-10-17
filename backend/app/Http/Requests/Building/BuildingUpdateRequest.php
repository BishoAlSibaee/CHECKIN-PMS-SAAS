<?php

namespace App\Http\Requests\Building;


use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Validation\Rule;
class BuildingUpdateRequest extends FormRequest
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
            'id'=> 'required|numeric|exists:buildings,id',
            'name'   => [
            'required',
            'string',
            Rule::unique('buildings', 'name')->ignore($this->id),
        ],
        'number' => [
            'required',
            'string',
            Rule::unique('buildings', 'number')->ignore($this->id),
        ],
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
