<?php

namespace App\Http\Requests;

use App\Domain\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSaleRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('sale')) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|Closure|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'description' => ['sometimes', 'required', 'string', 'max:255'],
            'amount' => ['sometimes', 'required', 'numeric', 'min:0.01', 'decimal:0,2'],
            'currency' => ['sometimes', 'required', Rule::in(['BRL'])],
            'sold_at' => [
                'sometimes',
                'required',
                'date',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (! is_string($value) || $value === '') {
                        return;
                    }

                    $timezone = app(TenantContext::class)->organization()->timezone;

                    try {
                        $soldAt = CarbonImmutable::parse($value, $timezone)->utc();
                    } catch (\Throwable) {
                        return;
                    }

                    if ($soldAt->isFuture()) {
                        $fail('A data da compra não pode estar no futuro no fuso da organização.');
                    }
                },
            ],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'external_id' => ['sometimes', 'nullable', 'string', 'max:100'],
        ];
    }
}
