<?php

namespace App\Http\Requests\User;

use Illuminate\Foundation\Http\FormRequest;

use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Contracts\Validation\Validator;
class UpdateUserRequest extends FormRequest
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
     */
    public function rules(): array
    {
        return [
            'id'            => 'required|numeric|exists:users,id',
            'jobtitle_id'   => 'nullable|numeric|exists:jobtitles,id',
            'department_id' => 'nullable|numeric|exists:departments,id',
            'mobile'        => 'nullable|string|unique:users,mobile,' . $this->id,
            'email'         => 'nullable|email|unique:users,email,' . $this->id,
            'discount_id'   => 'nullable|numeric|exists:discounts,id',
            'name'          => 'nullable|string',
            'permission_ids' => 'nullable|array',
            'permission_ids.*' => 'numeric|exists:permissions,id'
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
