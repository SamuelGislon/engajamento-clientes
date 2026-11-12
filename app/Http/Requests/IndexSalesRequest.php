<?php

namespace App\Http\Requests;

use App\Domain\Teams\CommercialAccessScope;
use App\Domain\Tenancy\TenantContext;
use App\Models\Company;
use App\Models\Sale;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class IndexSalesRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', Sale::class) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $organizationId = app(TenantContext::class)->id();
        $companyRule = Rule::exists('companies', 'id')->where('organization_id', $organizationId);

        if ($this->user()?->roleIn($organizationId) === 'salesperson') {
            $companyRule->where('owner_user_id', $this->user()->id);
        }

        return [
            'status' => ['nullable', Rule::in(['confirmed', 'cancelled'])],
            'company_id' => ['nullable', 'integer', $companyRule],
            'seller_id' => [
                'nullable',
                'integer',
                Rule::exists('organization_user', 'user_id')
                    ->where('organization_id', $organizationId)
                    ->where('role', 'salesperson')
                    ->where('is_active', true),
                ...($this->user()?->roleIn($organizationId) === 'salesperson' ? [Rule::in([$this->user()->id])] : []),
            ],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ];
    }

    /** @return array<int, Closure> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $user = $this->user();
            $organizationId = app(TenantContext::class)->id();

            if ($user === null || $organizationId === null || $user->roleIn($organizationId) !== 'manager') {
                return;
            }

            if ($this->filled('company_id')
                && Company::query()->visibleTo($user)->whereKey($this->integer('company_id'))->doesntExist()) {
                $validator->errors()->add('company_id', 'A empresa está fora das equipes que você administra.');
            }

            if ($this->filled('seller_id') && ! app(CommercialAccessScope::class)
                ->managedSellerIds($user, $organizationId)
                ->where('team_memberships.user_id', $this->integer('seller_id'))
                ->exists()) {
                $validator->errors()->add('seller_id', 'O vendedor está fora das equipes que você administra.');
            }
        }];
    }
}
