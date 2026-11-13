<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class SaveDataImportResolutionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'resolutions' => ['required', 'array', 'max:100'],
            'resolutions.*' => ['required', 'array:action,target_id'],
            'resolutions.*.action' => ['required', 'string', 'in:create,update,ignore,resolve'],
            'resolutions.*.target_id' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
