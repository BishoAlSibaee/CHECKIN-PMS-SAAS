<?php

namespace App\Http\Requests\User;

use Illuminate\Foundation\Http\FormRequest;

use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Contracts\Validation\Validator;
class AddUserRequest extends FormRequest
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
            'name'          => 'required|string|max:255',
            'job_number'    => 'required|string|unique:users,job_number',
            'jobtitle_id'   => 'required|numeric|exists:jobtitles,id',
            'department_id' => 'required|numeric|exists:departments,id',
            'mobile'        => 'required|string|unique:users,mobile',
            'email'         => 'required|email|unique:users,email',
            'discount_id'   => 'nullable|numeric|exists:discounts,id',
            'permission_ids' => 'nullable|array',
            'permission_ids.*' => 'numeric|exists:permissions,id',
            'password'      => 'required|string|min:6',
            'role_id'       => 'nullable|numeric|exists:roles,id',
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
