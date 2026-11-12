<?php

namespace App\Http\Requests;

use App\Domain\Tenancy\TenantContext;
use App\Models\Company;
use App\Models\Task;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Task::class) ?? false;
    }

    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        $organizationId = app(TenantContext::class)->id();
        $user = $this->user();
        $isSalesperson = $user?->roleIn($organizationId) === 'salesperson';

        return [
            'company_id' => [
                'nullable', 'integer',
                Rule::exists('companies', 'id')->where('organization_id', $organizationId),
                ...($isSalesperson ? [Rule::exists('companies', 'id')->where('organization_id', $organizationId)->where('owner_user_id', $user->id)] : []),
            ],
            'contact_id' => [
                'nullable', 'integer',
                Rule::exists('company_contacts', 'contact_id')
                    ->where('organization_id', $organizationId)
                    ->where('company_id', $this->integer('company_id')),
            ],
            'assigned_to' => [
                'nullable', 'integer',
                Rule::exists('organization_user', 'user_id')->where('organization_id', $organizationId)->where('is_active', true),
                ...($isSalesperson ? [Rule::in([$user->id])] : []),
            ],
            'title' => ['required', 'string', 'max:180'],
            'description' => ['nullable', 'string', 'max:5000'],
            'type' => ['required', Rule::in(['first_contact', 'call', 'whatsapp', 'email', 'meeting', 'visit', 'prospecting', 'follow_up', 'reactivation', 'custom'])],
            'priority' => ['required', Rule::in(['low', 'normal', 'high', 'urgent'])],
            'due_at' => ['required', 'date'],
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

            $companyId = $this->input('company_id');
            $assigneeId = $this->input('assigned_to');
            $company = $companyId === null ? null : Company::query()->visibleTo($user)->find((int) $companyId);

            if ($companyId !== null && $company === null) {
                $validator->errors()->add('company_id', 'A empresa está fora das equipes que você administra.');
            }

            if ($company !== null) {
                $isSelf = $assigneeId !== null && (int) $assigneeId === $user->id;
                $isSalespersonInCompanyTeam = $assigneeId !== null && DB::table('team_memberships')
                    ->where('organization_id', $organizationId)
                    ->where('team_id', $company->team_id)
                    ->where('user_id', (int) $assigneeId)
                    ->where('role', 'salesperson')
                    ->where('is_active', true)
                    ->exists();

                if (! $isSelf && ! $isSalespersonInCompanyTeam) {
                    $validator->errors()->add('assigned_to', 'A tarefa deve ser atribuída a você ou a um vendedor ativo da equipe da empresa.');
                }
            } else {
                $isSellerInManagedTeam = $assigneeId !== null && DB::table('team_memberships')
                    ->join('teams', 'teams.id', '=', 'team_memberships.team_id')
                    ->where('team_memberships.organization_id', $organizationId)
                    ->where('team_memberships.user_id', (int) $assigneeId)
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

                if (! $isSellerInManagedTeam) {
                    $validator->errors()->add('assigned_to', 'Tarefas sem empresa precisam ser atribuídas a um vendedor de uma equipe que você administra.');
                }
            }
        }];
    }
}
