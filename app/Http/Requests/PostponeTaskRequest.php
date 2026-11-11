<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PostponeTaskRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('postpone', $this->route('task')) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'due_at' => ['required', 'date', 'after:now'],
        ];
    }
}
