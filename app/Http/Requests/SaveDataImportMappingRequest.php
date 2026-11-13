<?php

namespace App\Http\Requests;

use App\Domain\Imports\CompanyImportFields;
use App\Domain\Imports\ContactImportFields;
use App\Domain\Imports\SaleImportFields;
use App\Models\DataImport;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class SaveDataImportMappingRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $record = DataImport::query()->find((int) $this->route('dataImport'));

        return $record !== null && $this->user()?->can('update', $record) === true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $record = DataImport::query()->find((int) $this->route('dataImport'));
        $fields = match ($record?->record_type) {
            'companies' => app(CompanyImportFields::class)->options(),
            'contacts' => app(ContactImportFields::class)->options(),
            'sales' => app(SaleImportFields::class)->options(),
            default => [],
        };
        $rules = ['mapping' => ['required', 'array']];

        foreach ($fields as $field => $options) {
            $rules['mapping.'.$field] = $options['required']
                ? ['required', 'integer', 'min:0']
                : ['sometimes', 'nullable', 'integer', 'min:0'];
        }

        return [
            ...$rules,
        ];
    }
}
