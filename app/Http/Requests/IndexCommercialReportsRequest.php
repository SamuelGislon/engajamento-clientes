<?php

namespace App\Http\Requests;

use App\Domain\Teams\CommercialAccessScope;
use App\Domain\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class IndexCommercialReportsRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->roleIn(app(TenantContext::class)->id()) !== null;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $organizationId = app(TenantContext::class)->id();

        return [
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'team_id' => [
                'nullable', 'integer',
                Rule::exists('teams', 'id')->where('organization_id', $organizationId)->where('status', 'active'),
            ],
            'seller_id' => [
                'nullable', 'integer',
                Rule::exists('organization_user', 'user_id')
                    ->where('organization_id', $organizationId)
                    ->where('role', 'salesperson')
                    ->where('is_active', true),
            ],
            'status' => ['nullable', Rule::in(['pending', 'in_progress', 'postponed', 'completed', 'cancelled'])],
            'type' => ['nullable', Rule::in(['first_contact', 'call', 'whatsapp', 'email', 'meeting', 'visit', 'prospecting', 'follow_up', 'reactivation', 'custom'])],
        ];
    }

    /** @return array<int, Closure> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $organization = app(TenantContext::class)->organization();
            $user = $this->user();
            if ($organization === null || $user === null) {
                return;
            }

            $timezone = $organization->timezone;
            $today = CarbonImmutable::now($timezone)->toDateString();
            foreach (['from', 'to'] as $dateFilter) {
                if ($this->filled($dateFilter) && $this->input($dateFilter) > $today) {
                    $validator->errors()->add($dateFilter, 'O período dos relatórios não pode avançar além de hoje.');
                }
            }

            if ($this->filled('from') && $this->filled('to')) {
                $from = CarbonImmutable::parse($this->input('from'), $timezone);
                $to = CarbonImmutable::parse($this->input('to'), $timezone);
                if ($from->diffInDays($to) > 365) {
                    $validator->errors()->add('from', 'O período máximo do relatório é de 366 dias.');
                }
            }

            $organizationId = $organization->id;
            $role = $user->roleIn($organizationId);
            $scope = app(CommercialAccessScope::class);

            if ($role === 'manager' && $this->filled('team_id') && ! $scope->managedTeamIds($user, $organizationId)
                ->where('team_memberships.team_id', $this->integer('team_id'))
                ->exists()) {
                $validator->errors()->add('team_id', 'A equipe está fora do seu escopo de gestão.');
            }

            if ($role === 'manager' && $this->filled('seller_id') && ! $scope->managedSellerIds($user, $organizationId)
                ->where('team_memberships.user_id', $this->integer('seller_id'))
                ->exists()) {
                $validator->errors()->add('seller_id', 'O vendedor está fora das equipes que você administra.');
            }

            if ($role === 'salesperson' && $this->filled('seller_id') && $this->integer('seller_id') !== $user->id) {
                $validator->errors()->add('seller_id', 'Vendedores só podem consultar seus próprios indicadores.');
            }

            if ($role === 'salesperson' && $this->filled('team_id') && ! $user->teams()
                ->where('teams.organization_id', $organizationId)
                ->where('teams.id', $this->integer('team_id'))
                ->where('teams.status', 'active')
                ->wherePivot('role', 'salesperson')
                ->wherePivot('is_active', true)
                ->exists()) {
                $validator->errors()->add('team_id', 'A equipe selecionada não pertence à sua carteira.');
            }

            if ($this->filled('team_id') && $this->filled('seller_id') && ! DB::table('team_memberships')
                ->where('organization_id', $organizationId)
                ->where('team_id', $this->integer('team_id'))
                ->where('user_id', $this->integer('seller_id'))
                ->where('role', 'salesperson')
                ->where('is_active', true)
                ->exists()) {
                $validator->errors()->add('seller_id', 'O vendedor selecionado não pertence à equipe escolhida.');
            }
        }];
    }
}
