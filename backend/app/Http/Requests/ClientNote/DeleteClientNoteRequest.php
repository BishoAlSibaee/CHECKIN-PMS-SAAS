<?php

namespace App\Http\Requests\ClientNote;

use App\Models\ClientNote;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DeleteClientNoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'id' => ['required', Rule::exists(ClientNote::class, 'id')],
        ];
    }
}
