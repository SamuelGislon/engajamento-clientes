<?php

namespace App\Http\Requests;

use App\Domain\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class StoreSaleRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $company = $this->route('company');

        return $company !== null && Gate::allows('view', $company);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|Closure|array<mixed>|string>
     */
    public function rules(): array
    {
        $organizationId = app(TenantContext::class)->id();
        $sellerRules = [
            'required',
            'integer',
            Rule::exists('organization_user', 'user_id')->where('organization_id', $organizationId),
        ];

        if ($this->user()?->roleIn($organizationId) === 'salesperson') {
            $sellerRules[] = Rule::in([$this->user()->id]);
        }

        return [
            'seller_id' => $sellerRules,
            'external_id' => ['nullable', 'string', 'max:100'],
            'description' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'min:0.01', 'decimal:0,2'],
            'currency' => ['required', Rule::in(['BRL'])],
            'sold_at' => [
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
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
