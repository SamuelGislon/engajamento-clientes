<?php

namespace App\Http\Requests;

use App\Domain\Tenancy\TenantContext;
use App\Models\Company;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

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
            Rule::exists('organization_user', 'user_id')
                ->where('organization_id', $organizationId)
                ->where('role', 'salesperson')
                ->where('is_active', true),
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

    /** @return array<int, Closure> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $user = $this->user();
            $organizationId = app(TenantContext::class)->id();
            $company = $this->route('company');

            if ($user === null || $organizationId === null || ! $company instanceof Company) {
                return;
            }

            if ($company->team_id !== null && $user->roleIn($organizationId) !== 'manager') {
                $sellerInCompanyTeam = DB::table('team_memberships')
                    ->where('organization_id', $organizationId)
                    ->where('team_id', $company->team_id)
                    ->where('user_id', (int) $this->input('seller_id'))
                    ->where('role', 'salesperson')
                    ->where('is_active', true)
                    ->exists();

                if (! $sellerInCompanyTeam) {
                    $validator->errors()->add('seller_id', 'O vendedor precisa fazer parte da equipe principal da empresa.');
                }
            }

            if ($user->roleIn($organizationId) !== 'manager') {
                return;
            }

            if ($company->team_id === null) {
                $validator->errors()->add('seller_id', 'A empresa não está vinculada a uma equipe ativa.');

                return;
            }

            $sellerBelongsToManagedCompanyTeam = DB::table('team_memberships')
                ->join('teams', 'teams.id', '=', 'team_memberships.team_id')
                ->where('team_memberships.organization_id', $organizationId)
                ->where('team_memberships.team_id', $company->team_id)
                ->where('team_memberships.user_id', (int) $this->input('seller_id'))
                ->where('team_memberships.role', 'salesperson')
                ->where('team_memberships.is_active', true)
                ->where('teams.status', 'active')
                ->whereExists(fn ($teams) => $teams->selectRaw('1')
                    ->from('team_memberships as manager_memberships')
                    ->whereColumn('manager_memberships.team_id', 'teams.id')
                    ->whereColumn('manager_memberships.organization_id', 'teams.organization_id')
                    ->where('manager_memberships.user_id', $user->id)
                    ->where('manager_memberships.role', 'manager')
                    ->where('manager_memberships.is_active', true))
                ->exists();

            if (! $sellerBelongsToManagedCompanyTeam) {
                $validator->errors()->add('seller_id', 'A venda deve ser atribuída a um vendedor da equipe desta empresa.');
            }
        }];
    }
}
